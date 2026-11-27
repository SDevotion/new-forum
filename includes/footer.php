        </div>
    </main>
    
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>ForumHub</h3>
                    <p>Современная платформа для обсуждений и обмена знаниями.</p>
                </div>
                <div class="footer-section">
                    <h4>Навигация</h4>
                    <ul>
                        <li><a href="/index.php">Главная</a></li>
                        <li><a href="/members.php">Участники</a></li>
                        <li><a href="/stats.php">Статистика</a></li>
                        <li><a href="/search.php">Поиск</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?= date('Y') ?> ForumHub. Все права защищены.</p>
            </div>
        </div>
    </footer>
    
    <?php if (!empty($mdEditorAssets)): ?>
        <link rel="stylesheet" href="/assets/vendor/easymde/easymde.min.css?v=<?= @filemtime(__DIR__ . '/../assets/vendor/easymde/easymde.min.css') ?: 1 ?>">
        <script src="/assets/vendor/easymde/easymde.min.js?v=<?= @filemtime(__DIR__ . '/../assets/vendor/easymde/easymde.min.js') ?: 1 ?>"></script>
    <?php endif; ?>
    <script src="/assets/js/main.js?v=<?= @filemtime(__DIR__ . '/../assets/js/main.js') ?: 1 ?>"></script>
</body>
</html>
