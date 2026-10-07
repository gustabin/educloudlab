<?php

declare(strict_types=1);

namespace EduCloud\Modules\ObjectStorage;

use EduCloud\Core\App;
use EduCloud\Core\Auth\TenantContext;
use EduCloud\Core\Exceptions\ValidationException;
use EduCloud\Core\Request;
use EduCloud\Core\Response;
use EduCloud\Core\Validator;
use EduCloud\Modules\Workspaces\WorkspaceController;

/** Object storage API (M7): containers, objects, metadata, tiers, lifecycle policies and downloads. */
final class ObjectStorageController
{
    private ObjectStorageService $service;

    public function __construct(private readonly App $app)
    {
        $this->service = new ObjectStorageService($app);
    }

    public function containers(Request $request): Response
    {
        $items = $this->service->containers($this->ctx($request), WorkspaceController::id($request, 'resource_id'));
        return Response::json($items, 200, '', ['request_id' => $request->requestId, 'total' => count($items)]);
    }

    public function createContainer(Request $request): Response
    {
        $body = $request->json();
        $valid = static function () use ($body): array {
            $data = Validator::validate(array_diff_key($body, ['lifecycle' => 1]), [
                'name' => ['required', 'string', 'regex:' . ObjectStorageService::CONTAINER_NAME],
            ]);
            return ['name' => (string) $data['name'], 'lifecycle' => ObjectStorageService::validLifecycle($body['lifecycle'] ?? null)];
        };
        $container = $this->service->createContainer($request, $this->ctx($request), WorkspaceController::id($request, 'resource_id'), $valid);
        return Response::json($container, 201, 'Contenedor creado.', ['request_id' => $request->requestId]);
    }

    public function showContainer(Request $request): Response
    {
        $q = Validator::validate($request->query, ['prefix' => ['string', 'max:255']]);
        $container = $this->service->getContainer($this->ctx($request), self::id($request, 'container_id'), (string) ($q['prefix'] ?? ''));
        return Response::json($container, 200, '', ['request_id' => $request->requestId]);
    }

    public function updateContainer(Request $request): Response
    {
        $body = $request->json();
        $valid = static function () use ($body): ?array {
            Validator::validate(array_diff_key($body, ['lifecycle' => 1]), []);
            if (!array_key_exists('lifecycle', $body)) {
                throw new ValidationException([
                    ['field' => 'lifecycle', 'code' => 'required', 'message' => 'Indica la política (o null para quitarla).'],
                ]);
            }
            return ObjectStorageService::validLifecycle($body['lifecycle']);
        };
        $container = $this->service->setLifecycle($request, $this->ctx($request), self::id($request, 'container_id'), $valid);
        return Response::json($container, 200, 'Política guardada.', ['request_id' => $request->requestId]);
    }

    public function deleteContainer(Request $request): Response
    {
        $this->service->deleteContainer($request, $this->ctx($request), self::id($request, 'container_id'));
        return Response::noContent();
    }

    public function putObject(Request $request): Response
    {
        $fields = array_diff_key($request->post, ['csrf_token' => 1]);
        $max = (int) $this->app->config->get('quotas.tags_per_resource', 10);
        $valid = static function () use ($fields, $max): array {
            $data = Validator::validate($fields, [
                'key' => ['required', 'string', 'max:255', 'regex:' . ObjectStorageService::OBJECT_KEY],
                'metadata' => ['string', 'max:4000'],
                'tier' => ['in:hot,cool,archive'],
            ]);
            $metadata = isset($data['metadata']) && $data['metadata'] !== '' ? json_decode((string) $data['metadata'], true) : null;
            if (isset($data['metadata']) && $data['metadata'] !== '' && !is_array($metadata)) {
                throw new ValidationException([['field' => 'metadata', 'code' => 'json', 'message' => 'Los metadatos deben ser un objeto JSON.']]);
            }
            return [
                'key' => (string) $data['key'],
                'metadata' => ObjectStorageService::validMetadata($metadata, $max),
                'tier' => (string) ($data['tier'] ?? 'hot'),
            ];
        };
        $object = $this->service->putObject($request, $this->ctx($request), self::id($request, 'container_id'), $valid, $request->file('file'));
        return Response::json($object, 201, 'Objeto guardado.', ['request_id' => $request->requestId]);
    }

    public function showObject(Request $request): Response
    {
        $object = $this->service->getObject($this->ctx($request), self::id($request, 'object_id'));
        return Response::json($object, 200, '', ['request_id' => $request->requestId]);
    }

    public function updateObject(Request $request): Response
    {
        $body = $request->json();
        $max = (int) $this->app->config->get('quotas.tags_per_resource', 10);
        $valid = static function (array $row) use ($body, $max): array {
            $data = Validator::validate(array_diff_key($body, ['metadata' => 1]), ['tier' => ['in:hot,cool,archive']]);
            if ($data === [] && !array_key_exists('metadata', $body)) {
                throw new ValidationException([['field' => 'tier', 'code' => 'required', 'message' => 'Indica al menos un cambio.']]);
            }
            return [
                'metadata' => array_key_exists('metadata', $body)
                    ? ObjectStorageService::validMetadata($body['metadata'], $max)
                    : \EduCloud\Core\Format::jsonColumn($row['metadata']),
                'tier' => (string) ($data['tier'] ?? $row['tier']),
            ];
        };
        $object = $this->service->updateObject($request, $this->ctx($request), self::id($request, 'object_id'), $valid);
        return Response::json($object, 200, 'Objeto actualizado.', ['request_id' => $request->requestId]);
    }

    /** Always an attachment of opaque type: stored content is never rendered by the browser. */
    public function download(Request $request): Response
    {
        $file = $this->service->download($request, $this->ctx($request), self::id($request, 'object_id'));
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']) ?: 'objeto';
        return Response::file($file['path'], [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($file['name']),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function deleteObject(Request $request): Response
    {
        $this->service->deleteObject($request, $this->ctx($request), self::id($request, 'object_id'));
        return Response::noContent();
    }

    private static function id(Request $request, string $param): string
    {
        return WorkspaceController::id($request, $param);
    }

    private function ctx(Request $request): TenantContext
    {
        /** @var TenantContext $ctx */
        $ctx = $request->attribute('tenant');
        return $ctx;
    }
}
