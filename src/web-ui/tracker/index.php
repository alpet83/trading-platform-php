<?php
    session_start();
    require_once 'lib/common.php'; // esctext, init_remote_db
    require_once 'lib/db_config.php';
    require_once 'lib/db_tools.php'; // твой mysql_ex и init_remote_db

    // Инициализируем подключение к БД trading
    $db = init_remote_db('trading');

    // Обработка выбора активного портфеля через GET
    if (isset($_GET['set_portfolio'])) {
        $_SESSION['portfolio_id'] = (int)$_GET['set_portfolio'];
        header("Location: index.php");
        exit;
    }

    // Получаем список портфелей
    $portfolios = $db->query("SELECT id, name FROM portfolios__map ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
    $active_portfolio = $_SESSION['portfolio_id'] ?? null;
?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <title>Трекер криптопортфеля</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-dark text-white">
    <div class="container py-4">
        <h1 class="mb-4">Трекер криптопортфеля</h1>

        <!-- Селектор портфеля -->
        <form class="mb-4" method="get">
            <div class="row g-2 align-items-center">
                <div class="col-auto">
                    <label for="set_portfolio" class="form-label">Активный портфель:</label>
                </div>
                <div class="col-auto">
                    <select name="set_portfolio" id="set_portfolio" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Выберите портфель --</option>
                        <?php foreach ($portfolios as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $active_portfolio == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createPortfolioModal">Создать новый</button>
                </div>
            </div>
        </form>

        <?php if ($active_portfolio): ?>
            <div class="mb-3">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editPositionModal">Добавить/Редактировать позицию</button>
            </div>

            <!-- Таблица с позициями -->
            <div id="positionsTable">
                <p>Загрузка позиций...</p>
            </div>
        <?php else: ?>
            <p class="text-warning">Выберите активный портфель или создайте новый.</p>
        <?php endif; ?>
    </div>

    <!-- Модальное окно для создания портфеля -->
    <div class="modal fade" id="createPortfolioModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content text-dark">
                <div class="modal-header">
                    <h5 class="modal-title">Создание нового портфеля</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="createPortfolioForm">
                        <div class="mb-3">
                            <label for="portfolioName" class="form-label">Название портфеля</label>
                            <input type="text" class="form-control" id="portfolioName" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="portfolioDescription" class="form-label">Описание</label>
                            <textarea class="form-control" id="portfolioDescription" name="description"></textarea>
                        </div>
                        <button type="submit" class="btn btn-success">Создать</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно для добавления/редактирования позиции -->
    <div class="modal fade" id="editPositionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content text-dark">
                <div class="modal-header">
                    <h5 class="modal-title">Добавить/Редактировать позицию</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editPositionForm">
                        <input type="hidden" name="portfolio_id" value="<?= $active_portfolio ?>">
                        <div class="mb-3">
                            <label for="cmId" class="form-label">CoinMarketCap ID</label>
                            <input type="number" class="form-control" id="cmId" name="cm_id" required>
                        </div>
                        <div class="mb-3">
                            <label for="amount" class="form-label">Количество</label>
                            <input type="number" step="any" class="form-control" id="amount" name="amount" required>
                        </div>
                        <div class="mb-3">
                            <label for="avgPrice" class="form-label">Средняя цена USD</label>
                            <input type="number" step="any" class="form-control" id="avgPrice" name="avg_price_usd" required>
                        </div>
                        <div class="mb-3">
                            <label for="note" class="form-label">Заметка</label>
                            <textarea class="form-control" id="note" name="note"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    <?php if ($active_portfolio): ?>
        document.addEventListener("DOMContentLoaded", function () {
            loadPositions();
        });

        function loadPositions() {
            fetch("api.php?portfolio_id=<?= $active_portfolio ?>")
                .then(response => response.text())
                .then(html => document.getElementById("positionsTable").innerHTML = html)
                .catch(err => console.error(err));
        }

        document.getElementById("editPositionForm").addEventListener("submit", function (e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch("api.php", {
                method: "POST",
                body: formData
            }).then(() => {
                var modal = bootstrap.Modal.getInstance(document.getElementById("editPositionModal"));
                modal.hide();
                loadPositions();
            });
        });
    <?php endif; ?>

        document.getElementById("createPortfolioForm").addEventListener("submit", function (e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch("api.php", {
                method: "POST",
                body: formData
            }).then(() => location.reload());
        });
    </script>
    </body>
    </html>
