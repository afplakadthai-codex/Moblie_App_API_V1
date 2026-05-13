<?php
declare(strict_types=1);

/**
 * Bettavaro SMTP / Mail Config
 *
 * ปรับค่า SMTP ให้ตรงกับโฮสต์จริงของคุณ
 * แนะนำให้ย้าย password ไปเก็บนอก public_html ในอนาคต
 */

if (!function_exists('mail_engine_config')) {
     function mail_engine_config()
    {
        return array(
            'enabled' => true,

            // SMTP Server
            'host' => 'localhost',
            // ตั้งค่าตามคำแนะนำของผู้ให้บริการโฮสต์: ใช้พอร์ต 25 ไม่เข้ารหัสและไม่ต้องยืนยันตัวตน
            'port' => 25,
            'encryption' => '',
            'auth' => false,
            'username' => 'subport@bettavaro.com',
            'password' => 'kik@08418',

            // Sender
            'from_email' => 'subport@bettavaro.com',
            'from_name'  => 'Bettavaro',
            'reply_to' => 'subport@bettavaro.com',
 

            // Debug / logging
            'timeout' => 20,
            'debug' => false,
            'charset' => 'UTF-8',
            // When true, disables strict peer/host verification during
            // SMTP TLS negotiation.  Enable this if your mail server uses
            // a self‑signed certificate or otherwise fails certificate
            // verification, as indicated by the `certificate verify failed`
            // errors in your mail logs.  For maximum security, keep this
            // disabled and instead install a valid certificate on your
            // SMTP server.
            'allow_insecure' => true,

        );
    }
}