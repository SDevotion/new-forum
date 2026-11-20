// assets/js/main.js

// Лимиты из настроек форума (includes/header.php -> window.FG_CONFIG)
const FG = Object.assign({
    titleMin: 5, titleMax: 200, topicMin: 10, replyMin: 2,
    messageMax: 20000, reportMin: 5, reportMax: 500, passwordMin: 6
}, window.FG_CONFIG || {});

// ===== Markdown -> HTML (клиентский предпросмотр) =====
function mdToHtml(src) {
    let text = String(src).replace(/\r\n?/g, '\n').replace(/\u0000/g, '');
    const esc = s => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                      .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    text = esc(text);

    const codeBlocks = [];
    text = text.replace(/```([a-zA-Z0-9_+-]*)\n([\s\S]*?)\n```/g, (m, lang, code) => {
        codeBlocks.push('<pre class="md-pre"' + (lang ? ' data-lang="'+lang+'"' : '') +
                        '><code>' + code.replace(/\n+$/,'') + '</code></pre>');
        return '\u0000CODE' + (codeBlocks.length-1) + '\u0000';
    });

    const inlineCodes = [];
    text = text.replace(/`([^`\n]+)`/g, (m, code) => {
        inlineCodes.push('<code class="md-code">' + code + '</code>');
        return '\u0000IC' + (inlineCodes.length-1) + '\u0000';
    });

    text = text.replace(/\[quote=([^\]\n]{1,60})\]([\s\S]*?)\[\/quote\]/g,
        (m, a, t) => '<div class="post-quote"><div class="post-quote-author">' +
                     a.trim() + ' писал(а):</div>' + t.trim() + '</div>');

    const lines = text.split('\n');
    const out = [];
    let para = [], inList = false, listType = '', inQuote = false;

    const flushPara = () => { if (para.length) { out.push('<p>'+para.join('<br>')+'</p>'); para = []; } };
    const closeList = () => { if (inList) { out.push('</'+listType+'>'); inList=false; listType=''; } };
    const closeQuote = () => { if (inQuote) { out.push('</blockquote>'); inQuote=false; } };

    for (let raw of lines) {
        const line = raw.trim();
        if (!line) { flushPara(); closeList(); closeQuote(); continue; }
        if (/^\u0000CODE\d+\u0000$/.test(line)) { flushPara(); closeList(); closeQuote(); out.push(line); continue; }
        if (/^(-{3,}|\*{3,}|_{3,})$/.test(line)) { flushPara(); closeList(); closeQuote(); out.push('<hr class="md-hr">'); continue; }

        let m;
        if ((m = line.match(/^(#{1,4})\s+(.+)$/))) {
            flushPara(); closeList(); closeQuote();
            const lvl = m[1].length + 2;
            out.push('<h'+lvl+' class="md-h">'+m[2]+'</h'+lvl+'>'); continue;
        }
        if ((m = line.match(/^&gt;\s?(.*)$/))) {
            flushPara(); closeList();
            if (!inQuote) { out.push('<blockquote class="md-quote">'); inQuote = true; }
            out.push(m[1]+'<br>'); continue;
        }
        closeQuote();

        if ((m = line.match(/^[-*+]\s+(.+)$/))) {
            flushPara();
            if (!inList || listType !== 'ul') { closeList(); out.push('<ul class="md-list">'); inList=true; listType='ul'; }
            out.push('<li>'+m[1]+'</li>'); continue;
        }
        if ((m = line.match(/^\d+[.)]\s+(.+)$/))) {
            flushPara();
            if (!inList || listType !== 'ol') { closeList(); out.push('<ol class="md-list">'); inList=true; listType='ol'; }
            out.push('<li>'+m[1]+'</li>'); continue;
        }
        closeList();
        para.push(line);
    }
    flushPara(); closeList(); closeQuote();

    let html = out.join('\n');
    html = html.replace(/\*\*(?=\S)([\s\S]+?)(?<=\S)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/(^|[^*\w])\*(?=\S)([^*\n]+?)(?<=\S)\*(?!\*)/g, '$1<em>$2</em>');
    html = html.replace(/~~(?=\S)([\s\S]+?)(?<=\S)~~/g, '<del>$1</del>');
    html = html.replace(/\[([^\]\n]{1,200})\]\((https?:\/\/[^\s)]{1,500})\)/g,
        (m, t, u) => '<a href="'+u+'" class="md-link" target="_blank" rel="nofollow noopener noreferrer">'+t+'</a>');
    html = html.replace(/(^|[^">=\w])(https?:\/\/[^\s<>"']{1,500})/g,
        (m, p, u) => p+'<a href="'+u+'" class="md-link" target="_blank" rel="nofollow noopener noreferrer">'+u+'</a>');

    html = html.replace(/(^|[^\w>])@([a-zA-Z0-9_]{3,50})/g, '$1<span class="mention">@$2</span>');

    html = html.replace(/\u0000IC(\d+)\u0000/g, (m,i)=>inlineCodes[+i]);
    html = html.replace(/\u0000CODE(\d+)\u0000/g, (m,i)=>codeBlocks[+i]);
    return html;
}

document.addEventListener('DOMContentLoaded', function() {
    
    // ===== Закрытие алертов =====
    const alertCloseButtons = document.querySelectorAll('.alert-close');
    alertCloseButtons.forEach(button => {
        button.addEventListener('click', function() {
            const alert = this.closest('.alert');
            alert.style.animation = 'slideUp 0.3s ease';
            setTimeout(() => alert.remove(), 300);
        });
    });
    
    // Автоматическое скрытие алертов через 5 секунд
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            if (alert.parentElement) {
                alert.style.animation = 'slideUp 0.3s ease';
                setTimeout(() => alert.remove(), 300);
            }
        }, 5000);
    });
    
    // ===== Валидация формы регистрации =====
    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
        registerForm.addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            const passwordConfirm = document.getElementById('password_confirm').value;
            
            let errors = [];
            
            // Валидация username
            if (username.length < 3 || username.length > 50) {
                errors.push('Имя пользователя должно содержать 3-50 символов');
            }
            if (!/^[a-zA-Z0-9_]+$/.test(username)) {
                errors.push('Имя пользователя может содержать только латиницу, цифры и _');
            }
            
            // Валидация email
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                errors.push('Введите корректный email');
            }
            
            // Валидация пароля
            if (password.length < FG.passwordMin) {
                errors.push('Пароль должен содержать минимум ' + FG.passwordMin + ' символов');
            }
            
            if (password !== passwordConfirm) {
                errors.push('Пароли не совпадают');
            }
            
            if (errors.length > 0) {
                e.preventDefault();
                showErrors(errors);
            }
        });
        
        // Валидация в реальном времени
        const passwordInput = document.getElementById('password');
        const passwordConfirmInput = document.getElementById('password_confirm');
        
        passwordConfirmInput.addEventListener('input', function() {
            if (this.value && passwordInput.value !== this.value) {
                this.setCustomValidity('Пароли не совпадают');
                this.style.borderColor = '#ef4444';
            } else {
                this.setCustomValidity('');
                this.style.borderColor = '';
            }
        });
        
        passwordInput.addEventListener('input', function() {
            if (passwordConfirmInput.value && this.value !== passwordConfirmInput.value) {
                passwordConfirmInput.setCustomValidity('Пароли не совпадают');
                passwordConfirmInput.style.borderColor = '#ef4444';
            } else {
                passwordConfirmInput.setCustomValidity('');
                passwordConfirmInput.style.borderColor = '';
            }
        });
    }
    
    // ===== Функция отображения ошибок =====
    function showErrors(errors) {
        // Удаляем предыдущие ошибки
        const existingAlert = document.querySelector('.alert-error');
        if (existingAlert) {
            existingAlert.remove();
        }
        
        // Создаем новый алерт
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-error';
        
        const errorList = document.createElement('ul');
        errorList.className = 'error-list';
        errors.forEach(error => {
            const li = document.createElement('li');
            li.textContent = error;
            errorList.appendChild(li);
        });
        
        const closeBtn = document.createElement('button');
        closeBtn.className = 'alert-close';
        closeBtn.innerHTML = '&times;';
        closeBtn.addEventListener('click', function() {
            alertDiv.remove();
        });
        
        alertDiv.appendChild(errorList);
        alertDiv.appendChild(closeBtn);
        
        const form = document.querySelector('.auth-form')
                  || document.getElementById('topicForm')
                  || document.getElementById('replyForm')
                  || document.querySelector('form');
        if (form && form.parentNode) {
            form.parentNode.insertBefore(alertDiv, form);
        } else {
            document.body.prepend(alertDiv);
        }
        
        // Прокрутка к ошибкам
        alertDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    
    // ===== Счётчик символов в форме темы =====
    const contentInput = document.getElementById('content');
    const charCount = document.getElementById('charCount');
    if (contentInput && charCount) {
        const updateCount = () => {
            const len = contentInput.value.length;
            charCount.textContent = len;
            charCount.style.color = len > FG.messageMax ? '#ef4444' : '';
        };
        contentInput.addEventListener('input', updateCount);
        updateCount();
    }

    // ===== Валидация формы темы =====
    const topicForm = document.getElementById('topicForm');
    if (topicForm) {
        topicForm.addEventListener('submit', function(e) {
            const title = document.getElementById('title').value.trim();
            const content = document.getElementById('content').value.trim();
            let errors = [];

            if (title.length < FG.titleMin) errors.push('Заголовок должен содержать минимум ' + FG.titleMin + ' символов');
            if (title.length > FG.titleMax) errors.push('Заголовок не должен превышать ' + FG.titleMax + ' символов');
            if (content.length < FG.topicMin) errors.push('Сообщение должно содержать минимум ' + FG.topicMin + ' символов');
            if (content.length > FG.messageMax) errors.push('Сообщение слишком длинное (максимум ' + FG.messageMax + ' символов)');

            if (errors.length > 0) {
                e.preventDefault();
                showErrors(errors);
            }
        });
    }

    // ===== Ответы: цитирование =====
    const replyContent = document.getElementById('replyContent');
    const replyCharCount = document.getElementById('replyCharCount');

    function updateReplyCount() {
        if (!replyContent || !replyCharCount) return;
        const len = replyContent.value.length;
        replyCharCount.textContent = len;
        replyCharCount.style.color = len > FG.messageMax ? '#ef4444' : '';
    }

    if (replyContent) {
        replyContent.addEventListener('input', updateReplyCount);
        updateReplyCount();
    }

    document.querySelectorAll('.btn-quote').forEach(button => {
        button.addEventListener('click', function() {
            if (!replyContent) return;

            const author = this.dataset.author;
            let text = this.dataset.content.replace(/\s+/g, ' ').trim();
            if (text.length > 300) text = text.slice(0, 300) + '…';

            const quote = `[quote=${author}]${text}[/quote]\n`;
            replyContent.value += (replyContent.value ? '\n' : '') + quote;

            updateReplyCount();
            replyContent.dispatchEvent(new Event('input'));
            document.getElementById('reply').scrollIntoView({ behavior: 'smooth', block: 'center' });
            replyContent.focus();
            replyContent.setSelectionRange(replyContent.value.length, replyContent.value.length);
        });
    });

    const clearQuoteBtn = document.getElementById('clearQuote');
    if (clearQuoteBtn && replyContent) {
        clearQuoteBtn.addEventListener('click', function() {
            if (replyContent.value && !confirm('Очистить поле ответа?')) return;
            replyContent.value = '';
            updateReplyCount();
            replyContent.dispatchEvent(new Event('input'));
            replyContent.focus();
        });
    }

    // ===== Валидация формы ответа =====
    const replyForm = document.getElementById('replyForm');
    if (replyForm) {
        replyForm.addEventListener('submit', function(e) {
            const text = replyContent.value.trim();
            let errors = [];

            if (text.length < FG.replyMin) errors.push('Сообщение должно содержать минимум ' + FG.replyMin + ' символов');
            if (text.length > FG.messageMax) errors.push('Сообщение слишком длинное (максимум ' + FG.messageMax + ' символов)');

            if (errors.length > 0) {
                e.preventDefault();
                showErrors(errors);
                return;
            }

            // Блокировка повторной отправки
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Отправка...';
        });
    }

    // ===== Подтверждение удаления =====
    document.querySelectorAll('.js-confirm-delete').forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!confirm('Удалить это сообщение? Действие необратимо.')) {
                e.preventDefault();
            }
        });
    });
    
    // ===== Превью аватара =====
    const avatarInput = document.getElementById('avatar');
    const avatarPreview = document.getElementById('avatarPreview');
    if (avatarInput && avatarPreview) {
        avatarInput.addEventListener('change', function() {
            const file = this.files[0];
            if (!file) return;

            const avatarMax = (FG.avatarMaxMb || 2) * 1024 * 1024;
            if (file.size > avatarMax) {
                showErrors(['Файл слишком большой. Максимум ' + (FG.avatarMaxMb || 2) + ' МБ']);
                this.value = '';
                return;
            }

            if (!/^image\/(jpeg|png|gif|webp)$/.test(file.type)) {
                showErrors(['Допустимы только JPG, PNG, GIF, WEBP']);
                this.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = e => { avatarPreview.src = e.target.result; };
            reader.readAsDataURL(file);
        });
    }

    // ===== Валидация смены пароля =====
    const passwordForm = document.getElementById('passwordForm');
    if (passwordForm) {
        const np = document.getElementById('new_password');
        const npc = document.getElementById('new_password_confirm');

        const checkMatch = () => {
            if (npc.value && np.value !== npc.value) {
                npc.setCustomValidity('Пароли не совпадают');
                npc.style.borderColor = '#ef4444';
            } else {
                npc.setCustomValidity('');
                npc.style.borderColor = '';
            }
        };
        np.addEventListener('input', checkMatch);
        npc.addEventListener('input', checkMatch);

        passwordForm.addEventListener('submit', function(e) {
            let errors = [];
            if (np.value.length < FG.passwordMin) errors.push('Новый пароль должен содержать минимум ' + FG.passwordMin + ' символов');
            if (np.value !== npc.value) errors.push('Новые пароли не совпадают');
            if (errors.length) {
                e.preventDefault();
                showErrors(errors);
            }
        });
    }

    // ===== Подтверждение удаления аватара =====
    document.querySelectorAll('.js-confirm-avatar-delete').forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!confirm('Удалить текущий аватар?')) e.preventDefault();
        });
    });

    // ===== Модальное окно жалобы =====
    const reportModal = document.getElementById('reportModal');
    if (reportModal) {
        const typeInput = document.getElementById('reportTargetType');
        const idInput = document.getElementById('reportTargetId');
        const titleEl = document.getElementById('reportModalTitle');
        const reasonEl = document.getElementById('reportReason');

        document.querySelectorAll('.btn-report').forEach(btn => {
            btn.addEventListener('click', function() {
                typeInput.value = this.dataset.type;
                idInput.value = this.dataset.id;
                titleEl.textContent = this.dataset.type === 'post' ? 'Жалоба на сообщение' : 'Жалоба на тему';
                reportModal.classList.add('open');
                reasonEl.focus();
            });
        });

        reportModal.addEventListener('click', function(e) {
            if (e.target === reportModal || e.target.classList.contains('js-modal-close')) {
                reportModal.classList.remove('open');
            }
        });

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') reportModal.classList.remove('open');
        });

        document.getElementById('reportForm').addEventListener('submit', function(e) {
            if (reasonEl.value.trim().length < FG.reportMin) {
                e.preventDefault();
                alert('Опишите причину (минимум ' + FG.reportMin + ' символов)');
            }
        });
    }

    // ===== Смена роли с подтверждением =====
    document.querySelectorAll('.js-auto-submit').forEach(sel => {
        sel.addEventListener('change', function() {
            if (confirm('Изменить роль пользователя?')) {
                this.form.submit();
            } else {
                [...this.options].forEach((o, i) => { if (o.defaultSelected) this.selectedIndex = i; });
            }
        });
    });

    // ===== Подтверждения админ-действий =====
    document.querySelectorAll('.js-confirm-ban').forEach(f => {
        f.addEventListener('submit', e => { if (!confirm('Изменить статус блокировки этого пользователя?')) e.preventDefault(); });
    });
    document.querySelectorAll('.js-confirm-structure-delete').forEach(f => {
        f.addEventListener('submit', e => { if (!confirm('Удалить безвозвратно? Действие необратимо.')) e.preventDefault(); });
    });
    document.querySelectorAll('.js-confirm-topic-delete').forEach(f => {
        f.addEventListener('submit', e => { if (!confirm('Удалить тему со всеми сообщениями?')) e.preventDefault(); });
    });

    // ===== Форма редактирования =====
    const editForm = document.getElementById('editForm');
    if (editForm) {
        const contentEl = document.getElementById('content');
        const titleEl = document.getElementById('title');
        const counter = document.getElementById('charCount');

        const upd = () => {
            if (!counter) return;
            const len = contentEl.value.length;
            counter.textContent = len;
            counter.style.color = len > FG.messageMax ? '#ef4444' : '';
        };
        contentEl.addEventListener('input', upd);
        upd();

        const initial = contentEl.value + (titleEl ? titleEl.value : '');
        window.addEventListener('beforeunload', function(e) {
            const current = contentEl.value + (titleEl ? titleEl.value : '');
            if (current !== initial && !editForm.dataset.submitted) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        editForm.addEventListener('submit', function(e) {
            const text = contentEl.value.trim();
            const minLen = titleEl ? FG.topicMin : FG.replyMin;
            let errors = [];

            if (titleEl) {
                const t = titleEl.value.trim();
                if (t.length < FG.titleMin) errors.push('Заголовок должен содержать минимум ' + FG.titleMin + ' символов');
                if (t.length > FG.titleMax) errors.push('Заголовок не должен превышать ' + FG.titleMax + ' символов');
            }
            if (text.length < minLen) errors.push('Сообщение должно содержать минимум ' + minLen + ' символов');
            if (text.length > FG.messageMax) errors.push('Сообщение слишком длинное (максимум ' + FG.messageMax + ' символов)');

            if (errors.length) { e.preventDefault(); showErrors(errors); return; }

            editForm.dataset.submitted = '1';
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Сохранение...';
        });
    }

    // ===== Подтверждение отката версии =====
    document.querySelectorAll('.js-confirm-restore').forEach(f => {
        f.addEventListener('submit', e => {
            if (!confirm('Восстановить эту версию? Текущая сохранится в истории.')) e.preventDefault();
        });
    });

    // ===== Markdown-редактор =====
    document.querySelectorAll('.md-editor').forEach(wrap => {
        const ta = wrap.querySelector('textarea');
        const preview = wrap.querySelector('.md-preview-body');
        const tabs = wrap.querySelectorAll('.md-tab');
        const panes = wrap.querySelectorAll('.md-pane');
        const previewPane = wrap.querySelector('.md-pane[data-pane="preview"]');

        const renderPreview = () => {
            preview.innerHTML = ta.value.trim() ? mdToHtml(ta.value) : '<p class="md-empty">Нечего показывать</p>';
        };

        ta.addEventListener('input', () => {
            if (previewPane.classList.contains('active')) renderPreview();
        });

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                panes.forEach(p => p.classList.remove('active'));
                tab.classList.add('active');
                wrap.querySelector('.md-pane[data-pane="'+tab.dataset.tab+'"]').classList.add('active');
                if (tab.dataset.tab === 'preview') renderPreview();
            });
        });

        const surround = (before, after, placeholder) => {
            const s = ta.selectionStart, e = ta.selectionEnd;
            const sel = ta.value.slice(s, e) || placeholder;
            ta.setRangeText(before + sel + after, s, e, 'end');
            ta.focus();
            ta.dispatchEvent(new Event('input'));
        };
        const linePrefix = (prefix) => {
            const s = ta.selectionStart;
            const start = ta.value.lastIndexOf('\n', s - 1) + 1;
            ta.setRangeText(prefix, start, start, 'end');
            ta.focus();
            ta.dispatchEvent(new Event('input'));
        };

        wrap.querySelectorAll('.md-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const a = btn.dataset.action;
                if (a === 'bold') surround('**','**','жирный');
                else if (a === 'italic') surround('*','*','курсив');
                else if (a === 'strike') surround('~~','~~','зачёркнутый');
                else if (a === 'code') surround('`','`','код');
                else if (a === 'codeblock') surround('\n```\n','\n```\n','код');
                else if (a === 'link') surround('[','](https://)','текст ссылки');
                else if (a === 'quote') linePrefix('> ');
                else if (a === 'ul') linePrefix('- ');
                else if (a === 'ol') linePrefix('1. ');
                else if (a === 'h') linePrefix('### ');
                else if (a === 'hr') surround('\n\n---\n\n','','');
            });
        });

        ta.addEventListener('keydown', e => {
            if (e.key === 'Tab' && !e.shiftKey) {
                e.preventDefault();
                ta.setRangeText('  ', ta.selectionStart, ta.selectionEnd, 'end');
                return;
            }
            if (!(e.ctrlKey || e.metaKey)) return;
            const k = e.key.toLowerCase();
            if (k === 'b') { e.preventDefault(); surround('**','**','жирный'); }
            else if (k === 'i') { e.preventDefault(); surround('*','*','курсив'); }
            else if (k === 'k') { e.preventDefault(); surround('[','](https://)','текст ссылки'); }
            else if (k === 'enter') { e.preventDefault(); ta.closest('form').requestSubmit(); }
        });
    });

    // ===== Автодополнение @упоминаний =====
    document.querySelectorAll('.md-editor textarea').forEach(ta => {
        let box = null, items = [], active = 0, start = -1;

        const closeBox = () => { if (box) { box.remove(); box = null; items = []; } };

        const pick = (name) => {
            const pos = ta.selectionStart;
            ta.setRangeText('@' + name + ' ', start, pos, 'end');
            closeBox();
            ta.focus();
            ta.dispatchEvent(new Event('input'));
        };

        const render = () => {
            if (!items.length) { closeBox(); return; }
            if (!box) {
                box = document.createElement('div');
                box.className = 'mention-box';
                ta.closest('.md-editor').appendChild(box);
            }
            box.innerHTML = items.map((u, i) =>
                '<div class="mention-item' + (i === active ? ' active' : '') + '" data-name="' + u.username + '">' +
                '<img src="' + (u.avatar || '/assets/images/default-avatar.svg') + '" alt="">' + u.username + '</div>'
            ).join('');
            box.querySelectorAll('.mention-item').forEach(el => {
                el.addEventListener('mousedown', e => { e.preventDefault(); pick(el.dataset.name); });
            });
        };

        ta.addEventListener('input', async () => {
            const pos = ta.selectionStart;
            const m = ta.value.slice(0, pos).match(/@([a-zA-Z0-9_]{1,20})$/);
            if (!m) { closeBox(); return; }
            start = pos - m[0].length;
            try {
                const res = await fetch('/api/users.php?q=' + encodeURIComponent(m[1]));
                items = await res.json();
                active = 0;
                render();
            } catch (e) { closeBox(); }
        });

        ta.addEventListener('keydown', e => {
            if (!box || !items.length) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); active = (active + 1) % items.length; render(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = (active - 1 + items.length) % items.length; render(); }
            else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); pick(items[active].username); }
            else if (e.key === 'Escape') { closeBox(); }
        });

        ta.addEventListener('blur', () => setTimeout(closeBox, 150));
    });

    // ===== Очистка прочитанных уведомлений =====
    document.querySelectorAll('.js-confirm-clear').forEach(f => {
        f.addEventListener('submit', e => { if (!confirm('Удалить все прочитанные уведомления?')) e.preventDefault(); });
    });

    // ===== Подсветка активной ссылки навигации =====
    const currentPath = window.location.pathname;
    const navLinks = document.querySelectorAll('.nav-link');
    navLinks.forEach(link => {
        if (link.getAttribute('href') === currentPath) {
            link.style.backgroundColor = 'var(--color-blue-50)';
            link.style.color = 'var(--color-blue-600)';
        }
    });
    
    // ===== Вложения: превью и валидация =====
    document.querySelectorAll('.attach-upload').forEach(box => {
        const input = box.querySelector('.attach-input');
        const list = box.querySelector('.attach-selected');
        const maxFiles = parseInt(box.dataset.maxFiles, 10) || 5;
        const maxSize = parseInt(box.dataset.maxSize, 10) || 5242880;
        if (!input || !list) return;

        // Сколько старых вложений останется (с учётом отмеченных «удалить»)
        const remainingExisting = () => {
            const boxes = box.querySelectorAll('input[name="attach_remove[]"]');
            let kept = 0;
            boxes.forEach(cb => { if (!cb.checked) kept++; });
            return kept;
        };
        const slots = () => Math.max(0, maxFiles - remainingExisting());
        box.querySelectorAll('input[name="attach_remove[]"]').forEach(cb =>
            cb.addEventListener('change', render));

        const fmt = b => b >= 1048576 ? (b / 1048576).toFixed(1) + ' МБ'
                       : b >= 1024 ? (b / 1024).toFixed(1) + ' КБ' : b + ' Б';

        // Удаляет файл из input по индексу (FileList неизменяем — пересобираем через DataTransfer)
        function removeAt(idx) {
            const dt = new DataTransfer();
            Array.from(input.files).forEach((f, i) => { if (i !== idx) dt.items.add(f); });
            input.files = dt.files;
            prevFiles = Array.from(input.files);
            render();
        }

        function render() {
            list.innerHTML = '';
            const files = Array.from(input.files);
            files.forEach((f, idx) => {
                const row = document.createElement('div');
                row.className = 'attach-selected-item';
                const bad = f.size > maxSize;
                if (bad) row.classList.add('attach-selected-bad');

                if (f.type.startsWith('image/') && !bad) {
                    const img = document.createElement('img');
                    img.className = 'attach-selected-thumb';
                    img.src = URL.createObjectURL(f);
                    img.onload = () => URL.revokeObjectURL(img.src);
                    row.appendChild(img);
                } else {
                    const icon = document.createElement('span');
                    icon.className = 'attach-file-icon';
                    icon.textContent = '📎';
                    row.appendChild(icon);
                }
                const name = document.createElement('span');
                name.className = 'attach-file-name';
                name.textContent = f.name;
                const size = document.createElement('span');
                size.className = 'attach-file-size';
                size.textContent = fmt(f.size) + (bad ? ' — слишком большой!' : '');
                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'attach-selected-remove';
                del.title = 'Убрать файл';
                del.textContent = '×';
                del.addEventListener('click', () => removeAt(idx));
                row.appendChild(name);
                row.appendChild(size);
                row.appendChild(del);
                list.appendChild(row);
            });
            const free = slots();
            if (files.length > free) {
                const warn = document.createElement('div');
                warn.className = 'attach-selected-bad';
                warn.textContent = 'Выбрано ' + files.length + ' файлов, можно добавить ещё ' + free + ' (лимит ' + maxFiles + ' на сообщение)';
                list.appendChild(warn);
            }
        }

        input.addEventListener('change', function() {
            // Накапливаем выбор: браузерный диалог заменяет FileList целиком,
            // поэтому объединяем с уже выбранными (деdup по имени+размеру+дате изменения)
            const merged = new DataTransfer();
            const seen = new Set();
            const key = f => f.name + '|' + f.size + '|' + f.lastModified;
            Array.from(prevFiles).concat(Array.from(input.files)).forEach(f => {
                const k = key(f);
                if (!seen.has(k)) { seen.add(k); merged.items.add(f); }
            });
            input.files = merged.files;
            prevFiles = Array.from(input.files);
            render();
        });
        let prevFiles = [];

        const form = box.closest('form');
        if (form) {
            form.addEventListener('submit', function(e) {
                const files = Array.from(input.files);
                if (!files.length) return;
                let errors = [];
                const free = slots();
                if (files.length > free) errors.push('Можно прикрепить не более ' + maxFiles + ' файлов на сообщение (можно добавить ещё ' + free + ')');
                files.forEach(f => {
                    if (f.size > maxSize) errors.push('«' + f.name + '» больше ' + fmt(maxSize));
                });
                if (errors.length) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    showErrors(errors);
                }
            }, true); // capture: раньше блокировки кнопки отправки
        }
    });

    // ===== Анимация появления элементов =====
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);
    
    const animatedElements = document.querySelectorAll('.stat-card');
    animatedElements.forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        observer.observe(el);
    });
});

// CSS анимация для скрытия алертов
const style = document.createElement('style');
style.textContent = `
    @keyframes slideUp {
        from {
            opacity: 1;
            transform: translateY(0);
        }
        to {
            opacity: 0;
            transform: translateY(-10px);
        }
    }
`;
document.head.appendChild(style);