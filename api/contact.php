<?php
/**
 * Contact form handler — Site Vous Plaît (sitevousplait.fr)
 * PHPMailer inclus directement (pas de Composer).
 *
 * Comportement :
 *  - Formulaire HTML classique (POST) -> redirection 302 vers /merci/ (succès)
 *    ou /contact/?error=1 (échec). Fonctionne SANS JavaScript.
 *  - Requête JSON (Accept: application/json ou ?format=json) -> réponse JSON.
 *
 * Identifiants SMTP dans config.php (NON versionné). Voir config.example.php.
 */

// Détecte si le client attend du JSON (fetch) ou une navigation classique
$wantsJson = (isset($_GET['format']) && $_GET['format'] === 'json')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']));

function respond($ok, $httpCode, $payload, $redirectTo) {
    global $wantsJson;
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($httpCode);
        echo json_encode($payload);
    } else {
        header('Location: ' . $redirectTo, true, 302);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 405, ['error' => 'Method not allowed'], '/contact/');
}

// --- CONFIGURATION ---
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    error_log('Contact form: missing api/config.php');
    respond(false, 500, ['error' => 'Server configuration error.'], '/contact/?error=config');
}
$config = require $configFile;

$smtpHost       = $config['smtpHost'];
$smtpUser       = $config['smtpUser'];
$smtpPass       = $config['smtpPass'];
$recipientEmail = $config['recipientEmail'];
$recipientName  = $config['recipientName'];

// SSL (465) puis fallback STARTTLS (587)
$smtpConfigs = [
    ['port' => 465, 'encryption' => 'smtps'],
    ['port' => 587, 'encryption' => 'tls'],
];

// --- RATE LIMITING (fichier) — 5 / heure / IP ---
$rateLimitDir = __DIR__ . '/../.rate-limit';
if (!is_dir($rateLimitDir)) {
    @mkdir($rateLimitDir, 0700, true);
}
$clientIp = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? 'unknown';
$rateLimitFile = $rateLimitDir . '/' . md5($clientIp);
$maxPerHour = 5;
if (file_exists($rateLimitFile)) {
    $data = json_decode(file_get_contents($rateLimitFile), true);
    if ($data && ($data['timestamp'] ?? 0) > time() - 3600) {
        if (($data['count'] ?? 0) >= $maxPerHour) {
            respond(false, 429, ['error' => 'Trop de requêtes. Réessayez plus tard.'], '/contact/?error=rate');
        }
        $data['count']++;
    } else {
        $data = ['timestamp' => time(), 'count' => 1];
    }
} else {
    $data = ['timestamp' => time(), 'count' => 1];
}
file_put_contents($rateLimitFile, json_encode($data));

// --- INPUT ---
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

// --- HONEYPOT ---
if (trim($input['website'] ?? '') !== '') {
    respond(true, 200, ['success' => true, 'message' => 'Merci !'], '/merci/');
}

// --- VALIDATION ---
$name    = trim($input['name'] ?? '');
$email   = trim($input['email'] ?? '');
$phone   = trim($input['phone'] ?? '');
$budget  = trim($input['budget'] ?? '');
$message = trim($input['message'] ?? '');

$errors = [];
if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 100) {
    $errors[] = 'Nom requis (2 à 100 caractères).';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email valide requis.';
}
if ($message === '' || mb_strlen($message) < 10 || mb_strlen($message) > 5000) {
    $errors[] = 'Message requis (10 à 5000 caractères).';
}
if (mb_strlen($budget) > 60) {
    $errors[] = 'Budget invalide.';
}
if (!empty($errors)) {
    respond(false, 422, ['error' => 'Validation échouée', 'details' => $errors], '/contact/?error=1');
}

// Échappement pour l'email HTML
$nameH    = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$phoneH   = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
$budgetH  = htmlspecialchars($budget, ENT_QUOTES, 'UTF-8');
$messageH = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

// --- PHPMailer ---
$libDir = __DIR__ . '/lib/PHPMailer';
require_once $libDir . '/Exception.php';
require_once $libDir . '/PHPMailer.php';
require_once $libDir . '/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$lastError = '';
$sent = false;
foreach ($smtpConfigs as $cfg) {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host     = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->Port     = $cfg['port'];
        $mail->CharSet  = 'UTF-8';
        $mail->SMTPSecure = $cfg['encryption'] === 'smtps'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPOptions = ['ssl' => [
            'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
        ]];

        $mail->setFrom($smtpUser, 'Site Vous Plaît — Formulaire de contact');
        $mail->addAddress($recipientEmail, $recipientName);
        $mail->addReplyTo($email, $name);

        $mail->isHTML(true);
        $mail->Subject = "Nouvelle demande de devis — {$name}";
        $mail->Body =
            "<h2>Nouvelle demande via sitevousplait.fr</h2>" .
            "<p><strong>Nom :</strong> {$nameH}</p>" .
            "<p><strong>Email :</strong> " . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "</p>" .
            "<p><strong>Téléphone :</strong> " . ($phoneH !== '' ? $phoneH : '-') . "</p>" .
            "<p><strong>Budget :</strong> " . ($budgetH !== '' ? $budgetH : '-') . "</p>" .
            "<hr><p><strong>Message :</strong></p><p>" . nl2br($messageH) . "</p>";
        $mail->AltBody =
            "Nom: {$name}\nEmail: {$email}\nTéléphone: {$phone}\nBudget: {$budget}\n\n{$message}";

        $mail->send();
        $sent = true;
        break;
    } catch (Exception $e) {
        $lastError = "Port {$cfg['port']} ({$cfg['encryption']}): " . $e->getMessage();
        error_log("Contact form [{$cfg['port']}]: " . $e->getMessage());
    }
}

if ($sent) {
    respond(true, 200, ['success' => true, 'message' => 'Message envoyé.'], '/merci/');
} else {
    error_log("Contact form: tous les SMTP ont échoué. Dernier: $lastError");
    respond(false, 500, ['error' => "Échec de l'envoi. Réessayez plus tard."], '/contact/?error=send');
}
