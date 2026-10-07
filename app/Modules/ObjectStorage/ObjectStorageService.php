<?php

declare(strict_types=1);

namespace EduCloud\Modules\ObjectStorage;

use EduCloud\Core\App;
use EduCloud\Core\Auth\Policy;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ApiException;
use EduCloud\Core\Exceptions\ConflictException;
use EduCloud\Core\Exceptions\ForbiddenException;
use EduCloud\Core\Exceptions\NotFoundException;
use EduCloud\Core\Exceptions\QuotaExceededException;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Format;
use EduCloud\Core\Request;
use EduCloud\Core\Ulid;
use EduCloud\Core\UploadedFile;
use EduCloud\Modules\Datasets\DataUploadValidator;
use EduCloud\Modules\Resources\ResourceService;
use EduCloud\Modules\Resources\ResourceTypes;
use EduCloud\Modules\Usage\UsageService;
use Throwable;

/**
 * Object storage (M7, LAB-002): containers inside a storage resource and objects inside containers.
 * Teaches containers, keys, metadata, access tiers (archive objects must be "rehydrated" before reading) and lifecycle
 * policies applied by the scheduler. Object keys are display names; bytes live under generated storage keys; the
 * Content-Type is assigned by the server from the validated format; downloads are always attachments.
 */
final class ObjectStorageService
{
    public const CONTAINER_NAME = '/^[a-z0-9][a-z0-9-]{2,62}$/D';
    public const OBJECT_KEY = '#^[A-Za-z0-9][A-Za-z0-9._ -]{0,99}(/[A-Za-z0-9][A-Za-z0-9._ -]{0,99}){0,9}$#D';
    public const TIERS = ['hot', 'cool', 'archive'];

    private ObjectStorageRepository $repo;
    private Policy $policy;

    public function __construct(private readonly App $app)
    {
        $this->repo = new ObjectStorageRepository($app->db());
        $this->policy = new Policy($app->config);
    }

    /** @return list<array<string, mixed>> */
    public function containers(TenantContext $ctx, string $resourcePublicId): array
    {
        $resource = $this->storageResource($ctx, $resourcePublicId);
        return array_map([self::class, 'presentContainer'], $this->repo->containers($ctx, (int) $resource['id']));
    }

    /**
     * @param callable(): array{name: string, lifecycle: array<string, int>|null} $validInput
     * @return array<string, mixed>
     */
    public function createContainer(Request $request, TenantContext $ctx, string $resourcePublicId, callable $validInput): array
    {
        $resource = $this->storageResource($ctx, $resourcePublicId);
        $this->assertCan($request, $ctx, (int) $resource['owner_user_id'], 'create', 'resource', $resourcePublicId);
        $input = $validInput();
        $max = (int) $this->app->config->get('quotas.containers_per_storage', 20);
        $created = $this->app->db()->transaction(function () use ($ctx, $resource, $input, $max): array {
            // Same lock as ResourceService::delete: a storage being deleted cannot gain containers.
            if ($this->repo->lockStorage($ctx, (int) $resource['id']) !== 'active') {
                throw new NotFoundException('El almacenamiento no existe.');
            }
            if ($this->repo->countContainers($ctx, (int) $resource['id']) >= $max) {
                throw new QuotaExceededException("Este almacenamiento ya tiene el máximo de $max contenedores.");
            }
            if ($this->repo->containerNameTaken($ctx, (int) $resource['id'], $input['name'])) {
                throw new ConflictException('Ya existe un contenedor con ese nombre en este almacenamiento.');
            }
            return $this->repo->createContainer(
                $ctx,
                (int) $resource['workspace_id'],
                (int) $resource['id'],
                $input['name'],
                $input['lifecycle']
            );
        });
        $this->audit($request, $ctx, 'storage.container_create', 'container', $created['public_id']);
        return $this->getContainer($ctx, $created['public_id'], '');
    }

    /** @return array<string, mixed> container with its objects (optionally filtered by key prefix) */
    public function getContainer(TenantContext $ctx, string $publicId, string $prefix): array
    {
        $row = $this->findContainer($ctx, $publicId);
        $objects = $this->repo->objects($ctx, (int) $row['id'], $prefix, 500);
        return self::presentContainer($row) + ['objects' => array_map([self::class, 'presentObject'], $objects)];
    }

    /**
     * @param callable(): (array<string, int>|null) $validLifecycle
     * @return array<string, mixed>
     */
    public function setLifecycle(Request $request, TenantContext $ctx, string $publicId, callable $validLifecycle): array
    {
        $row = $this->findContainer($ctx, $publicId);
        $this->assertCan($request, $ctx, (int) $row['owner_user_id'], 'update', 'container', $publicId);
        $lifecycle = $validLifecycle();
        $this->repo->setLifecycle($ctx, (int) $row['id'], $lifecycle);
        $this->audit($request, $ctx, 'storage.lifecycle', 'container', $publicId, ['lifecycle' => $lifecycle]);
        return $this->getContainer($ctx, $publicId, '');
    }

    public function deleteContainer(Request $request, TenantContext $ctx, string $publicId): void
    {
        $row = $this->findContainer($ctx, $publicId);
        $this->assertCan($request, $ctx, (int) $row['owner_user_id'], 'delete', 'container', $publicId);
        $this->app->db()->transaction(function () use ($ctx, $row): void {
            // Recounted under the container lock that uploads also take (no object can slip in meanwhile);
            // the foreign key (ON DELETE RESTRICT) refuses a non-empty delete as a last line of defence.
            if (!$this->repo->lockContainer($ctx, (int) $row['id'])) {
                throw new NotFoundException('El contenedor no existe.');
            }
            if ($this->repo->countObjects($ctx, (int) $row['id']) > 0) {
                throw new ApiException(409, 'CONTAINER_NOT_EMPTY', 'Elimina primero los objetos del contenedor.');
            }
            $this->repo->deleteContainer($ctx, (int) $row['id']);
        });
        $this->audit($request, $ctx, 'storage.container_delete', 'container', $publicId);
    }

    /**
     * Uploads (or replaces, same key) an object.
     *
     * @param callable(): array{key: string, metadata: array<string, string>, tier: string} $validInput
     * @return array<string, mixed>
     */
    public function putObject(
        Request $request,
        TenantContext $ctx,
        string $containerPublicId,
        callable $validInput,
        ?UploadedFile $file
    ): array {
        $container = $this->findContainer($ctx, $containerPublicId);
        $this->assertCan($request, $ctx, (int) $container['owner_user_id'], 'create', 'container', $containerPublicId);
        $input = $validInput();
        $validator = new DataUploadValidator((int) $this->app->config->get('quotas.upload_max_bytes'), DataUploadValidator::OBJECT_EXTENSIONS);
        $format = $validator->validate($file);
        /** @var UploadedFile $file */
        $size = (int) filesize($file->tmpPath);
        $usage = new UsageService($this->app);
        $usage->assertRoom($ctx, $size);

        $storage = $this->app->storage();
        $storageKey = Ulid::generate();
        $target = $storage->objectFile($ctx->tenantPublicId, (string) $container['workspace_public_id'], $storageKey);
        $storage->ensureDir(dirname($target));
        if (!$file->moveTo($target)) {
            throw new ApiException(400, 'UPLOAD_FAILED', 'No se pudo guardar el archivo. Inténtalo de nuevo.');
        }
        try {
            $sha = (string) hash_file('sha256', $target, true);
            $max = (int) $this->app->config->get('quotas.objects_per_container', 200);
            $put = function () use ($ctx, $container, $input, $storageKey, $size, $format, $sha, $max, $usage): ?string {
                // Container first (same order as deleteContainer), then the per-user lock for the quota.
                if (!$this->repo->lockContainer($ctx, (int) $container['id'])) {
                    throw new NotFoundException('El contenedor no existe.');
                }
                $this->app->db()->select(
                    'SELECT id FROM memberships WHERE tenant_id = ? AND user_id = ? FOR UPDATE',
                    [$ctx->tenantId, $ctx->userId]
                );
                $usage->assertRoom($ctx, $size); // re-checked under the per-user lock (concurrent uploads)
                $isNew = $this->repo->findByKey($ctx, (int) $container['id'], $input['key']) === null;
                if ($isNew && $this->repo->countObjects($ctx, (int) $container['id']) >= $max) {
                    throw new QuotaExceededException("Este contenedor ya tiene el máximo de $max objetos.");
                }
                return $this->repo->putObject(
                    $ctx,
                    (int) $container['id'],
                    $input['key'],
                    $storageKey,
                    $size,
                    DataUploadValidator::CONTENT_TYPES[$format],
                    $sha,
                    $input['metadata'],
                    $input['tier']
                );
            };
            $replaced = $this->app->db()->transaction($put);
        } catch (Throwable $e) {
            $storage->delete($target);
            throw $e;
        }
        if ($replaced !== null) {
            $storage->delete($storage->objectFile($ctx->tenantPublicId, (string) $container['workspace_public_id'], $replaced));
        }
        $object = $this->repo->findByKey($ctx, (int) $container['id'], $input['key']);
        $this->audit($request, $ctx, 'storage.object_put', 'object', (string) ($object['public_id'] ?? ''), [
            'bytes' => $size,
            'replaced' => $replaced !== null,
        ]);
        return self::presentObject((array) $object);
    }

    /** @return array<string, mixed> */
    public function getObject(TenantContext $ctx, string $publicId): array
    {
        return self::presentObject($this->findObject($ctx, $publicId));
    }

    /**
     * @param callable(array<string, mixed>): array{metadata: array<string, string>, tier: string} $validInput
     * @return array<string, mixed>
     */
    public function updateObject(Request $request, TenantContext $ctx, string $publicId, callable $validInput): array
    {
        $row = $this->findObject($ctx, $publicId);
        $this->assertCan($request, $ctx, (int) $row['owner_user_id'], 'update', 'object', $publicId);
        $input = $validInput($row);
        $this->repo->updateObject($ctx, (int) $row['id'], $input['metadata'], $input['tier']);
        $this->audit($request, $ctx, 'storage.object_update', 'object', $publicId, ['tier' => $input['tier']]);
        return $this->getObject($ctx, $publicId);
    }

    /** @return array{path: string, name: string, bytes: int} the file to stream as an attachment */
    public function download(Request $request, TenantContext $ctx, string $publicId): array
    {
        $row = $this->findObject($ctx, $publicId);
        if ($row['tier'] === 'archive') {
            throw new ApiException(
                409,
                'OBJECT_ARCHIVED',
                'El objeto está en el nivel archive: cámbialo a hot o cool (rehidratación) para poder leerlo.'
            );
        }
        $path = $this->app->storage()->objectFile($ctx->tenantPublicId, (string) $row['workspace_public_id'], (string) $row['storage_key']);
        if (!is_file($path)) {
            throw new NotFoundException('El contenido del objeto no está disponible.');
        }
        $this->audit($request, $ctx, 'storage.object_download', 'object', $publicId);
        return ['path' => $path, 'name' => basename((string) $row['object_key']), 'bytes' => (int) $row['bytes']];
    }

    public function deleteObject(Request $request, TenantContext $ctx, string $publicId): void
    {
        $row = $this->findObject($ctx, $publicId);
        $this->assertCan($request, $ctx, (int) $row['owner_user_id'], 'delete', 'object', $publicId);
        $this->repo->deleteObject($ctx->tenantId, (int) $row['id']);
        $storage = $this->app->storage();
        $storage->delete($storage->objectFile($ctx->tenantPublicId, (string) $row['workspace_public_id'], (string) $row['storage_key']));
        $this->audit($request, $ctx, 'storage.object_delete', 'object', $publicId);
    }

    /**
     * Scheduler: applies container lifecycle policies (archive, then delete).
     *
     * @return array{int, int} [archived, deleted]
     */
    public function applyLifecycle(int $limit = 500): array
    {
        $archived = $deleted = 0;
        $storage = $this->app->storage();
        foreach ($this->repo->lifecycleDue($limit) as $o) {
            if ($o['action'] === 'delete') {
                $this->repo->deleteObject((int) $o['tenant_id'], (int) $o['id']);
                $file = $storage->objectFile((string) $o['tenant_public_id'], (string) $o['workspace_public_id'], (string) $o['storage_key']);
                $storage->delete($file);
                $deleted++;
            } else {
                $this->repo->archive((int) $o['tenant_id'], (int) $o['id']);
                $archived++;
            }
        }
        return [$archived, $deleted];
    }

    /** @return array<string, int>|null validated lifecycle (null = no policy) */
    public static function validLifecycle(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw new ValidationException([['field' => 'lifecycle', 'code' => 'object', 'message' => 'La política debe ser un objeto.']]);
        }
        $out = [];
        foreach ($value as $key => $days) {
            if (!in_array($key, ['archive_after_days', 'delete_after_days'], true)) {
                throw new ValidationException([
                    ['field' => "lifecycle.$key", 'code' => 'unknown_field', 'message' => 'Regla de ciclo de vida no permitida.'],
                ]);
            }
            if (!is_int($days) || $days < 1 || $days > 3650) {
                throw new ValidationException([
                    ['field' => "lifecycle.$key", 'code' => 'range', 'message' => 'Indica un número de días entre 1 y 3650.'],
                ]);
            }
            $out[$key] = $days;
        }
        if (isset($out['archive_after_days'], $out['delete_after_days']) && $out['archive_after_days'] >= $out['delete_after_days']) {
            throw new ValidationException([
                ['field' => 'lifecycle.delete_after_days', 'code' => 'order', 'message' => 'El borrado debe ocurrir después del archivado.'],
            ]);
        }
        return $out === [] ? null : $out;
    }

    /** @return array<string, string> */
    public static function validMetadata(mixed $value, int $max): array
    {
        return ResourceTypes::normaliseTags($value, $max);
    }

    /** @return array<string, mixed> storage resource visible to the caller */
    private function storageResource(TenantContext $ctx, string $resourcePublicId): array
    {
        $row = (new ResourceService($this->app))->findOrFail($ctx, $resourcePublicId);
        if ($row['type'] !== 'storage' || $row['status'] !== 'active') {
            throw new NotFoundException('El almacenamiento no existe.');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function findContainer(TenantContext $ctx, string $publicId): array
    {
        $row = $this->repo->findContainer($ctx, $publicId, $this->policy->seesWholeTenant($ctx));
        if ($row === null) {
            throw new NotFoundException('El contenedor no existe.');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function findObject(TenantContext $ctx, string $publicId): array
    {
        $row = $this->repo->findObject($ctx, $publicId, $this->policy->seesWholeTenant($ctx));
        if ($row === null) {
            throw new NotFoundException('El objeto no existe.');
        }
        return $row;
    }

    private function assertCan(Request $request, TenantContext $ctx, int $ownerUserId, string $permission, string $type, string $publicId): void
    {
        if (!$this->policy->canModify($ctx, $ownerUserId, $permission)) {
            $this->app->audit()->record($request, 'storage.' . $permission, 'denied', $ctx->tenantId, $ctx->userId, $type, $publicId);
            throw new ForbiddenException();
        }
    }

    /** @param array<string, mixed> $meta */
    private function audit(Request $request, TenantContext $ctx, string $action, string $type, string $publicId, array $meta = []): void
    {
        $this->app->audit()->record($request, $action, 'success', $ctx->tenantId, $ctx->userId, $type, $publicId, $meta);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function presentContainer(array $row): array
    {
        $lifecycle = Format::jsonColumn($row['lifecycle']);
        return [
            'id' => (string) $row['public_id'],
            'storage_id' => (string) $row['resource_public_id'],
            'storage_name' => (string) $row['resource_name'],
            'name' => (string) $row['name'],
            'lifecycle' => $lifecycle === [] ? null : $lifecycle,
            'object_count' => (int) $row['object_count'],
            'total_bytes' => (int) $row['total_bytes'],
            'created_at' => Format::isoUtc((string) $row['created_at']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function presentObject(array $row): array
    {
        $metadata = Format::jsonColumn($row['metadata'] ?? null);
        return [
            'id' => (string) $row['public_id'],
            'container_id' => (string) $row['container_public_id'],
            'key' => (string) $row['object_key'],
            'bytes' => (int) $row['bytes'],
            'content_type' => (string) $row['content_type'],
            'sha256' => bin2hex((string) $row['sha256']),
            'metadata' => $metadata === [] ? new \stdClass() : $metadata,
            'tier' => (string) $row['tier'],
            'created_at' => Format::isoUtc((string) $row['created_at']),
            'updated_at' => Format::isoUtc((string) $row['updated_at']),
        ];
    }
}
