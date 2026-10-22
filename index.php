<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
require __DIR__ . '/db.php';

const MAX_TABLE = 20;
const MAX_QTY = 20;

const STATUS_LABELS = [
    'new' => 'Нове',
    'preparing' => 'Готується',
    'ready' => 'Готове',
    'served' => 'Видано',
    'paid' => 'Оплачено',
];

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$pdo = db();
$menu = $pdo->query('SELECT id, name, price FROM menu_items ORDER BY id')->fetchAll();
$errors = [];
$table = 1;
$quantities = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $table = (int) ($_POST['table_number'] ?? 0);
    $quantities = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];

    if ($table < 1 || $table > MAX_TABLE) {
        $errors[] = 'Номер столика має бути від 1 до ' . MAX_TABLE . '.';
    }

    $priceById = array_column($menu, 'price', 'id');
    $lines = [];
    foreach ($quantities as $menuId => $qty) {
        $menuId = (int) $menuId;
        $qty = (int) $qty;
        if ($qty <= 0) {
            continue;
        }
        if ($qty > MAX_QTY) {
            $errors[] = 'Максимальна кількість однієї страви — ' . MAX_QTY . '.';
            break;
        }
        if (!isset($priceById[$menuId])) {
            $errors[] = 'Обрану страву не знайдено в меню.';
            break;
        }
        $lines[] = [$menuId, $qty, $priceById[$menuId]];
    }
    if (!$errors && !$lines) {
        $errors[] = 'Додайте хоча б одну страву до замовлення.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO orders (table_number) VALUES (?)')->execute([$table]);
            $orderId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare(
                'INSERT INTO order_items (order_id, menu_item_id, quantity, price) VALUES (?, ?, ?, ?)'
            );
            foreach ($lines as [$menuId, $qty, $price]) {
                $stmt->execute([$orderId, $menuId, $qty, $price]);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        header('Location: index.php?created=' . $orderId);
        exit;
    }
}

$orders = $pdo->query("
    SELECT o.id, o.table_number, o.status, o.created_at,
           COALESCE(SUM(oi.quantity * oi.price), 0) AS total,
           GROUP_CONCAT(CONCAT(m.name, ' × ', oi.quantity) SEPARATOR ', ') AS items
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN menu_items m ON m.id = oi.menu_item_id
    GROUP BY o.id
    ORDER BY o.id DESC
")->fetchAll();

$createdId = isset($_GET['created']) ? (int) $_GET['created'] : null;
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Cafe Orders</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #0f1115; --surface: #181b22; --surface-2: #1f232c; --border: #2a303c;
            --text: #e6e8ee; --muted: #9aa3b2; --accent: #e0a458; --danger: #f0716f; --success: #5fd0a0;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text);
               font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; line-height: 1.5; }
        .container { max-width: 980px; margin: 0 auto; padding: 28px 20px 48px; }
        h1 { font-size: 1.7rem; margin: 0 0 20px; }
        h2 { font-size: 1.2rem; margin: 32px 0 12px; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 20px; }
        .muted { color: var(--muted); }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; border: 1px solid; }
        .alert--ok  { background: #10281f; border-color: #1f5a41; color: var(--success); }
        .alert--err { background: #2d1717; border-color: #6b2b2b; color: var(--danger); }
        label.field { display: flex; flex-direction: column; gap: 6px; max-width: 220px; margin-bottom: 16px; }
        input[type=number] { background: var(--bg); color: var(--text); border: 1px solid var(--border);
                             border-radius: 8px; padding: 9px 12px; font-size: 1rem; width: 100%; }
        input[type=number]:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
        .row { display: grid; grid-template-columns: 1fr auto 90px; gap: 16px; align-items: center;
               padding: 8px 12px; margin-bottom: 8px; background: var(--surface-2);
               border: 1px solid var(--border); border-radius: 8px; }
        button { background: var(--accent); color: #1a1305; border: 0; border-radius: 8px;
                 padding: 10px 18px; font-size: 0.95rem; font-weight: 600; cursor: pointer; margin-top: 12px; }
        button:hover { filter: brightness(1.08); }
        .table-wrap { padding: 0; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border); }
        th { font-size: 0.8rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); background: var(--surface-2); }
        tbody tr:last-child td { border-bottom: 0; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: .8rem; font-weight: 600; }
        .badge--new { background: #1d2a44; color: #7fb0ff; }
        .badge--preparing { background: #3a2c12; color: var(--accent); }
        .badge--ready { background: #12372a; color: var(--success); }
        .badge--served { background: #2a2540; color: #b9a6ff; }
        .badge--paid { background: #262b35; color: var(--muted); }
    </style>
</head>
<body>
<div class="container">
    <h1>☕ Cafe Orders</h1>

    <?php if ($createdId): ?>
        <div class="alert alert--ok">Замовлення №<?= e($createdId) ?> успішно створено.</div>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert--err"><?= e($error) ?></div>
    <?php endforeach; ?>

    <h2>Нове замовлення</h2>
    <form method="post" class="card">
        <label class="field">
            <span>Номер столика</span>
            <input type="number" name="table_number" min="1" max="<?= MAX_TABLE ?>" value="<?= e($table) ?>" required>
        </label>
        <?php foreach ($menu as $item): ?>
            <label class="row">
                <span><?= e($item['name']) ?></span>
                <span class="muted"><?= e(number_format((float) $item['price'], 2, ',', ' ')) ?> грн</span>
                <input type="number" name="items[<?= e($item['id']) ?>]" min="0" max="<?= MAX_QTY ?>"
                       value="<?= e($quantities[$item['id']] ?? 0) ?>">
            </label>
        <?php endforeach; ?>
        <button type="submit">Створити замовлення</button>
    </form>

    <h2>Замовлення</h2>
    <?php if (!$orders): ?>
        <div class="card muted">Замовлень поки немає.</div>
    <?php else: ?>
        <div class="card table-wrap">
            <table>
                <thead>
                    <tr><th>№</th><th>Столик</th><th>Склад</th><th>Статус</th><th>Сума</th><th>Створено</th></tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td>#<?= e($o['id']) ?></td>
                        <td><?= e($o['table_number']) ?></td>
                        <td><?= e($o['items']) ?></td>
                        <td><span class="badge badge--<?= e($o['status']) ?>"><?= e(STATUS_LABELS[$o['status']] ?? $o['status']) ?></span></td>
                        <td><?= e(number_format((float) $o['total'], 2, ',', ' ')) ?> грн</td>
                        <td class="muted"><?= e($o['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>