(function () {
    "use strict";

    function boot() {
        var cfg = window.chatHearth || null;
        var root = document.getElementById("chathearth-root");
        if (!cfg || !root) {
            return;
        }

        init(cfg, root);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }

    function init(cfg, root) {
        var styles = cfg.styles || {};
        var messages = loadMessages();
        var welcomeShown = false;
        var isOpen = false;
        var isSending = false;
        var recaptchaWidgetId = null;
        var recaptchaUnlocked = !!(cfg.recaptchaEnabled && cfg.recaptchaPassed);

        applyCssVars();
        buildUi();

        function applyCssVars() {
            root.style.setProperty(
                "--chathearth-icon-bg",
                styles.iconBackgroundColor || "#0f172a",
            );
            root.style.setProperty(
                "--chathearth-icon-border",
                styles.iconBorderColor || "#1e293b",
            );
            root.style.setProperty(
                "--chathearth-icon-color",
                styles.iconColor || "#ffffff",
            );
            root.style.setProperty(
                "--chathearth-icon-size",
                (styles.iconSize || 56) + "px",
            );
            root.style.setProperty(
                "--chathearth-user-bubble",
                styles.userBubbleColor || "#0f172a",
            );
            root.style.setProperty(
                "--chathearth-assistant-bubble",
                styles.assistantBubbleColor || "#e2e8f0",
            );
            root.classList.add(
                "chathearth-pos-" + (styles.position || "bottom-right"),
            );
            root.classList.add(
                "chathearth-popup-" + (styles.popupSize || "medium"),
            );
            root.classList.add(
                "chathearth-shape-" + (styles.iconShape || "circle"),
            );
        }

        function buildUi() {
            root.innerHTML =
                '<button type="button" class="chathearth-launcher" aria-label="' +
                escapeAttr(cfg.i18n.open) +
                '">' +
                '<span class="chathearth-launcher-icon" aria-hidden="true"></span>' +
                "</button>" +
                '<div class="chathearth-panel">' +
                '<div class="chathearth-header">' +
                '<span class="chathearth-title"></span>' +
                '<div class="chathearth-header-actions">' +
                '<button type="button" class="chathearth-clear" title="' +
                escapeAttr(cfg.i18n.clear) +
                '">' +
                escapeHtml(cfg.i18n.clear) +
                "</button>" +
                '<button type="button" class="chathearth-close" aria-label="' +
                escapeAttr(cfg.i18n.close) +
                '">&times;</button>' +
                "</div></div>" +
                '<div class="chathearth-messages" role="log"></div>' +
                '<div class="chathearth-starters"></div>' +
                '<div class="chathearth-recaptcha"></div>' +
                '<form class="chathearth-composer">' +
                '<input type="text" class="chathearth-input" autocomplete="off" placeholder="' +
                escapeAttr(cfg.i18n.placeholder) +
                '" />' +
                '<button type="submit" class="chathearth-send">' +
                escapeHtml(cfg.i18n.send) +
                "</button>" +
                "</form></div>";

            var launcherIcon = root.querySelector(".chathearth-launcher-icon");
            fetch(styles.launcherUrl)
                .then(function (r) {
                    return r.text();
                })
                .then(function (svg) {
                    launcherIcon.innerHTML = svg;
                })
                .catch(function () {
                    launcherIcon.textContent = "💬";
                });

            root.querySelector(".chathearth-title").textContent =
                cfg.headerTitle || "";

            root.querySelector(".chathearth-launcher").addEventListener(
                "click",
                openPanel,
            );
            root.querySelector(".chathearth-close").addEventListener(
                "click",
                closePanel,
            );
            root.querySelector(".chathearth-clear").addEventListener(
                "click",
                clearChat,
            );
            root.querySelector(".chathearth-composer").addEventListener(
                "submit",
                onSubmit,
            );

            setupRecaptcha();
            renderStarters();
            renderMessages();
        }

        function setupRecaptcha() {
            if (!cfg.recaptchaEnabled || !cfg.recaptchaSiteKey) {
                return;
            }

            var mount = root.querySelector(".chathearth-recaptcha");
            if (!mount) {
                return;
            }

            if (recaptchaUnlocked) {
                hideRecaptcha();
                return;
            }

            renderRecaptchaWidget(mount);
        }

        function renderRecaptchaWidget(mount) {
            mount = mount || root.querySelector(".chathearth-recaptcha");
            if (!mount) {
                return;
            }

            function renderWidget() {
                if (
                    typeof window.grecaptcha === "undefined" ||
                    typeof window.grecaptcha.render !== "function"
                ) {
                    return;
                }
                if (recaptchaWidgetId !== null) {
                    return;
                }
                try {
                    recaptchaWidgetId = window.grecaptcha.render(mount, {
                        sitekey: cfg.recaptchaSiteKey,
                        size: "normal",
                    });
                } catch (e) {
                    /* already rendered or API not ready */
                }
            }

            if (
                typeof window.grecaptcha !== "undefined" &&
                typeof window.grecaptcha.ready === "function"
            ) {
                window.grecaptcha.ready(renderWidget);
            } else {
                var tries = 0;
                var timer = setInterval(function () {
                    tries += 1;
                    if (
                        typeof window.grecaptcha !== "undefined" &&
                        typeof window.grecaptcha.render === "function"
                    ) {
                        clearInterval(timer);
                        renderWidget();
                    } else if (tries > 40) {
                        clearInterval(timer);
                    }
                }, 250);
            }
        }

        function getRecaptchaToken() {
            if (!cfg.recaptchaEnabled || recaptchaUnlocked) {
                return "";
            }
            if (
                typeof window.grecaptcha === "undefined" ||
                recaptchaWidgetId === null
            ) {
                return "";
            }
            return window.grecaptcha.getResponse(recaptchaWidgetId) || "";
        }

        function hideRecaptcha() {
            recaptchaUnlocked = true;
            var mount = root.querySelector(".chathearth-recaptcha");
            if (mount) {
                mount.classList.add("is-unlocked");
                mount.setAttribute("hidden", "hidden");
            }
        }

        function showRecaptchaAgain() {
            recaptchaUnlocked = false;
            var mount = root.querySelector(".chathearth-recaptcha");
            if (mount) {
                mount.classList.remove("is-unlocked");
                mount.removeAttribute("hidden");
            }
            if (recaptchaWidgetId === null) {
                renderRecaptchaWidget(mount);
                return;
            }
            if (typeof window.grecaptcha !== "undefined") {
                try {
                    window.grecaptcha.reset(recaptchaWidgetId);
                } catch (e) {
                    /* ignore */
                }
            }
        }

        function openPanel() {
            isOpen = true;
            root.querySelector(".chathearth-panel").classList.add("is-open");
            root.querySelector(".chathearth-launcher").setAttribute(
                "aria-expanded",
                "true",
            );
            ensureWelcome();
            scrollMessagesToBottom();
            root.querySelector(".chathearth-input").focus();
        }

        function closePanel() {
            isOpen = false;
            root.querySelector(".chathearth-panel").classList.remove("is-open");
            root.querySelector(".chathearth-launcher").setAttribute(
                "aria-expanded",
                "false",
            );
        }

        function ensureWelcome() {
            if (welcomeShown || messages.length > 0) {
                renderStartersVisibility();
                return;
            }
            welcomeShown = true;
            if (cfg.welcome) {
                messages.push({
                    role: "assistant",
                    content: cfg.welcome,
                    localOnly: true,
                });
                saveMessages();
                renderMessages();
            }
            renderStartersVisibility();
        }

        function renderStarters() {
            var wrap = root.querySelector(".chathearth-starters");
            wrap.innerHTML = "";
            (cfg.starters || []).forEach(function (phrase) {
                var btn = document.createElement("button");
                btn.type = "button";
                btn.className = "chathearth-chip";
                btn.textContent = phrase;
                btn.addEventListener("click", function () {
                    sendMessage(phrase);
                });
                wrap.appendChild(btn);
            });
            renderStartersVisibility();
        }

        function renderStartersVisibility() {
            var wrap = root.querySelector(".chathearth-starters");
            var hasUser = messages.some(function (m) {
                return m.role === "user";
            });
            wrap.hidden = hasUser || !(cfg.starters && cfg.starters.length);
        }

        function clearChat() {
            messages = [];
            welcomeShown = false;
            saveMessages();
            renderMessages();
            ensureWelcome();
        }

        function onSubmit(e) {
            e.preventDefault();
            var input = root.querySelector(".chathearth-input");
            var text = (input.value || "").trim();
            if (!text) {
                return;
            }
            input.value = "";
            sendMessage(text);
        }

        function sendMessage(text) {
            if (isSending) {
                return;
            }

            var recaptchaToken = "";
            if (cfg.recaptchaEnabled && !recaptchaUnlocked) {
                recaptchaToken = getRecaptchaToken();
                if (!recaptchaToken) {
                    messages.push({
                        role: "assistant",
                        content:
                            (cfg.i18n && cfg.i18n.recaptchaRequired) ||
                            "Please complete the CAPTCHA before sending.",
                    });
                    saveMessages();
                    renderMessages();
                    return;
                }
            }

            messages.push({ role: "user", content: text });
            saveMessages();
            renderMessages();
            renderStartersVisibility();

            isSending = true;
            showTyping(true);

            var historyForApi = messages
                .filter(function (m) {
                    return !m.localOnly && m.role !== "typing";
                })
                .slice(0, -1)
                .map(function (m) {
                    return { role: m.role, content: m.content };
                });

            fetch(cfg.restUrl, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    "X-WP-Nonce": cfg.nonce,
                },
                body: JSON.stringify({
                    message: text,
                    history: historyForApi,
                    recaptcha_token: recaptchaToken,
                }),
            })
                .then(function (res) {
                    return res.json().then(function (data) {
                        return { ok: res.ok, status: res.status, data: data };
                    });
                })
                .then(function (result) {
                    showTyping(false);
                    isSending = false;

                    if (
                        !result.ok ||
                        !result.data ||
                        typeof result.data.reply !== "string"
                    ) {
                        var errCode = result.data && result.data.code;
                        if (
                            errCode === "chathearth_recaptcha_missing" ||
                            errCode === "chathearth_recaptcha_failed"
                        ) {
                            showRecaptchaAgain();
                        }
                        var errMsg =
                            (result.data && result.data.message) ||
                            (cfg.i18n && cfg.i18n.error) ||
                            "Error";
                        messages.push({ role: "assistant", content: errMsg });
                    } else {
                        if (cfg.recaptchaEnabled) {
                            hideRecaptcha();
                        }
                        messages.push({
                            role: "assistant",
                            content: result.data.reply,
                        });
                    }
                    saveMessages();
                    renderMessages();
                })
                .catch(function () {
                    showTyping(false);
                    isSending = false;
                    messages.push({
                        role: "assistant",
                        content: cfg.i18n.error,
                    });
                    saveMessages();
                    renderMessages();
                });
        }

        function showTyping(on) {
            var list = root.querySelector(".chathearth-messages");
            var existing = list.querySelector(".chathearth-typing");
            if (existing) {
                existing.remove();
            }
            if (!on) {
                return;
            }
            var row = document.createElement("div");
            row.className =
                "chathearth-msg chathearth-msg-assistant chathearth-typing";
            row.innerHTML =
                '<div class="chathearth-bubble"><span class="chathearth-dots" aria-label="' +
                escapeAttr(cfg.i18n.thinking) +
                '"><i></i><i></i><i></i></span></div>';
            list.appendChild(row);
            scrollMessagesToBottom();
        }

        function renderMessages() {
            var list = root.querySelector(".chathearth-messages");
            var typing = list.querySelector(".chathearth-typing");
            list.innerHTML = "";

            messages.forEach(function (m) {
                var row = document.createElement("div");
                row.className =
                    "chathearth-msg chathearth-msg-" +
                    (m.role === "user" ? "user" : "assistant");
                var bubble = document.createElement("div");
                bubble.className = "chathearth-bubble";
                if (m.role === "assistant") {
                    bubble.className += " chathearth-bubble-md";
                    bubble.innerHTML = renderMarkdown(m.content);
                } else {
                    bubble.textContent = m.content;
                }
                row.appendChild(bubble);
                list.appendChild(row);
            });

            if (typing) {
                list.appendChild(typing);
            }
            scrollMessagesToBottom();
        }

        /**
         * Scroll the message list to the latest item.
         * Uses a double rAF so layout is correct after the panel opens (hidden panels have no scroll height).
         */
        function scrollMessagesToBottom() {
            var list = root.querySelector(".chathearth-messages");
            if (!list) {
                return;
            }
            list.scrollTop = list.scrollHeight;
            window.requestAnimationFrame(function () {
                list.scrollTop = list.scrollHeight;
                window.requestAnimationFrame(function () {
                    list.scrollTop = list.scrollHeight;
                });
            });
        }

        /**
         * Lightweight safe Markdown → HTML for assistant replies.
         * Escape HTML first, then apply a common subset of Markdown.
         */
        function renderMarkdown(text) {
            var source = String(text || "");
            var blocks = [];

            source = source.replace(
                /```([\s\S]*?)```/g,
                function (_match, code) {
                    var token = "@@CHATHEARTHCODE" + blocks.length + "@@";
                    blocks.push(
                        '<pre class="chathearth-md-pre"><code>' +
                            escapeHtml(code.replace(/^\n|\n$/g, "")) +
                            "</code></pre>",
                    );
                    return token;
                },
            );

            source = escapeHtml(source);

            source = source.replace(
                /`([^`\n]+)`/g,
                '<code class="chathearth-md-code">$1</code>',
            );
            source = source.replace(
                /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,
                '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>',
            );
            source = source.replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");
            source = source.replace(/__([^_]+)__/g, "<strong>$1</strong>");
            source = source.replace(
                /(^|[^*])\*([^*\n]+)\*(?!\*)/g,
                "$1<em>$2</em>",
            );
            source = source.replace(
                /(^|[^_])_([^_\n]+)_(?!_)/g,
                "$1<em>$2</em>",
            );

            source = source.replace(
                /^### (.+)$/gm,
                '<h4 class="chathearth-md-h">$1</h4>',
            );
            source = source.replace(
                /^## (.+)$/gm,
                '<h3 class="chathearth-md-h">$1</h3>',
            );
            source = source.replace(
                /^# (.+)$/gm,
                '<h3 class="chathearth-md-h">$1</h3>',
            );

            blocks.forEach(function (html, i) {
                source = source.split("@@CHATHEARTHCODE" + i + "@@").join(html);
            });

            source = renderMarkdownBlocks(source);

            return source;
        }

        /**
         * Group consecutive list lines into a single ul/ol, then wrap paragraphs.
         */
        function renderMarkdownBlocks(source) {
            var lines = source.split("\n");
            var out = [];
            var i = 0;

            while (i < lines.length) {
                var line = lines[i];
                var unordered = line.match(/^[-*] (.+)$/);
                var ordered = line.match(/^\d+\. (.+)$/);

                if (unordered || ordered) {
                    var tag = unordered ? "ul" : "ol";
                    var items = [];
                    while (i < lines.length) {
                        var u = lines[i].match(/^[-*] (.+)$/);
                        var o = lines[i].match(/^\d+\. (.+)$/);
                        if (tag === "ul" && u) {
                            items.push("<li>" + u[1] + "</li>");
                            i++;
                            continue;
                        }
                        if (tag === "ol" && o) {
                            items.push("<li>" + o[1] + "</li>");
                            i++;
                            continue;
                        }
                        break;
                    }
                    out.push(
                        "<" +
                            tag +
                            ' class="chathearth-md-list">' +
                            items.join("") +
                            "</" +
                            tag +
                            ">",
                    );
                    continue;
                }

                if (/^<(?:h[34]|pre)\b/.test(line.trim())) {
                    out.push(line);
                    i++;
                    continue;
                }

                if (line.trim() === "") {
                    i++;
                    continue;
                }

                var para = [line];
                i++;
                while (i < lines.length) {
                    var next = lines[i];
                    if (
                        next.trim() === "" ||
                        /^[-*] /.test(next) ||
                        /^\d+\. /.test(next) ||
                        /^<(?:h[34]|pre|ul|ol)\b/.test(next.trim())
                    ) {
                        break;
                    }
                    para.push(next);
                    i++;
                }
                out.push(
                    '<p class="chathearth-md-p">' + para.join("<br>") + "</p>",
                );
            }

            return out.join("");
        }

        function loadMessages() {
            try {
                var raw = localStorage.getItem(cfg.storageKey);
                if (!raw) {
                    return [];
                }
                var parsed = JSON.parse(raw);
                return Array.isArray(parsed) ? parsed : [];
            } catch (e) {
                return [];
            }
        }

        function saveMessages() {
            try {
                var toStore = messages.map(function (m) {
                    return {
                        role: m.role,
                        content: m.content,
                        localOnly: !!m.localOnly,
                    };
                });
                localStorage.setItem(cfg.storageKey, JSON.stringify(toStore));
            } catch (e) {
                /* ignore quota */
            }
        }

        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;");
        }

        function escapeAttr(str) {
            return escapeHtml(str).replace(/'/g, "&#39;");
        }
    } // end init
})();
