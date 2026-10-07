<?php

declare(strict_types=1);

namespace EduCloud\Modules\Email;

/**
 * Email templates (Spanish). Every interpolated value is HTML-escaped in the HTML part.
 * Links are always built server-side from APP_URL; payloads never contain user-controlled URLs.
 */
final class Templates
{
    /**
     * @param array<string, mixed> $p
     * @return array{subject: string, html: string, text: string}
     */
    public static function render(string $template, array $p): array
    {
        $name = (string) ($p['display_name'] ?? '');
        $hello = $name === '' ? 'Hola,' : "Hola $name,";
        $url = (string) ($p['url'] ?? '');

        [$subject, $lines, $cta] = match ($template) {
            'verify_email' => [
                'Confirma tu correo en EduCloud Lab',
                [$hello, 'Gracias por registrarte en EduCloud Lab. Confirma tu dirección de correo para activar tu cuenta.',
                    'Para confirmarla necesitarás la contraseña que elegiste al registrarte. El enlace caduca en 24 horas.',
                    'Si no creaste esta cuenta, ignora este mensaje.'],
                'Confirmar mi correo',
            ],
            'password_reset' => [
                'Restablece tu contraseña de EduCloud Lab',
                [$hello, 'Recibimos una solicitud para restablecer tu contraseña.',
                    'El enlace caduca en 1 hora y solo puede usarse una vez. Si no lo solicitaste, ignora este mensaje: tu contraseña no cambiará.'],
                'Restablecer contraseña',
            ],
            'account_exists' => [
                'Intento de registro con tu correo en EduCloud Lab',
                [$hello, 'Alguien intentó crear una cuenta nueva con tu dirección de correo, pero ya tienes una cuenta.',
                    'Si fuiste tú, inicia sesión o restablece tu contraseña. Si no, puedes ignorar este mensaje.'],
                'Iniciar sesión',
            ],
            'password_changed' => [
                'Tu contraseña de EduCloud Lab ha cambiado',
                [$hello, 'La contraseña de tu cuenta se cambió correctamente y se cerraron todas las sesiones abiertas.',
                    'Si no fuiste tú, restablece tu contraseña de inmediato.'],
                'Iniciar sesión',
            ],
            'account_locked' => [
                'Bloqueo temporal de tu cuenta de EduCloud Lab',
                [$hello, 'Hubo varios intentos fallidos de inicio de sesión en tu cuenta, así que la bloqueamos durante 15 minutos.',
                    'Si no fuiste tú, alguien podría estar intentando adivinar tu contraseña. Restablecerla desbloquea la cuenta al instante.'],
                'Restablecer contraseña',
            ],
            'lab_expiry_warning' => [
                'Tu laboratorio de EduCloud Lab se eliminará pronto',
                [$hello, 'El entorno de tu laboratorio «' . (string) ($p['lab'] ?? '') . '» lleva tiempo sin actividad y se eliminará el '
                    . (string) ($p['date'] ?? '') . ' (UTC).',
                    'Si quieres conservarlo, ábrelo y guarda una respuesta o valida el laboratorio: cada actividad amplía el plazo. '
                    . 'Tu mejor puntuación se conserva aunque el entorno se elimine.'],
                'Abrir el laboratorio',
            ],
            default => throw new \InvalidArgumentException("Unknown email template '$template'"),
        };

        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $html = '<!doctype html><html lang="es"><body style="font-family:Segoe UI,Arial,sans-serif;color:#1f2933;line-height:1.5">';
        foreach ($lines as $line) {
            $html .= '<p>' . $e($line) . '</p>';
        }
        if ($url !== '') {
            $html .= '<p><a href="' . $e($url) . '" style="display:inline-block;padding:10px 18px;background:#0f766e;color:#fff;'
                . 'text-decoration:none;border-radius:6px">' . $e($cta) . '</a></p>'
                . '<p style="font-size:13px;color:#52606d">Si el botón no funciona, copia este enlace en tu navegador:<br>' . $e($url) . '</p>';
        }
        $html .= '<hr><p style="font-size:12px;color:#52606d">EduCloud Lab es un proyecto educativo independiente.</p></body></html>';

        $text = implode("\n\n", $lines) . ($url !== '' ? "\n\n$cta: $url" : '') . "\n\n-- \nEduCloud Lab";

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }
}
