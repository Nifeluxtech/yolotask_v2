<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Growth\Checkin;
use YoloTask\Money\Money;
use YoloTask\Security\Csrf;

/* Earner: daily check-in (POST only; the card lives on the dashboard). */
if (yolotask_role() !== 'earner') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('checkin.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') yolotask_redirect('dashboard.php');
if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
    yolotask_flash('Your form expired. Please try again.', 'error');
    yolotask_redirect('dashboard.php');
}
try {
    $res = Checkin::checkIn($pdo, $user);
    yolotask_flash('Checked in: ' . Money::format($res['amount']) . ' added to your wallet. ' . ($res['streak'] > 1 ? $res['streak'] . '-day streak!' : 'Day 1 of your streak.'));
} catch (\PDOException $e) {
    Logger::error('checkin.failed', ['exception' => get_class($e)]);
    yolotask_flash('Something went wrong. Please try again.', 'error');
} catch (RuntimeException $e) {
    yolotask_flash($e->getMessage(), str_starts_with($e->getMessage(), 'You already checked in') ? 'warning' : 'error');
} catch (Throwable $e) {
    Logger::error('checkin.failed', ['exception' => get_class($e)]);
    yolotask_flash('Something went wrong. Please try again.', 'error');
}
yolotask_redirect('dashboard.php');
