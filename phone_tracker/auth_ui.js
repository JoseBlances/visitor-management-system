/*
 * Shared pieces of the sign-in and account-security screens: password rules (the
 * same checks the server makes), a strength meter, a 6-digit code input, QR codes,
 * and copy/download helpers.
 */
(function () {
    "use strict";

    const QR_LIBRARY_URL = "https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js";
    const FALLBACK_POLICY = { min_length: 10, max_length: 64, common_passwords: [], common_words: [], sequences: [] };

    function lower(value) {
        return String(value || "").toLowerCase();
    }

    function characters(value) {
        return Array.from(String(value || ""));
    }

    function isCommonPassword(password, policy) {
        const value = lower(password);
        if ((policy.common_passwords || []).indexOf(value) !== -1) {
            return true;
        }
        const core = value.replace(/^[\d\W_]+|[\d\W_]+$/g, "");
        if (core && (policy.common_words || []).indexOf(core) !== -1) {
            return true;
        }
        const sequences = policy.sequences || [];
        for (let i = 0; i < sequences.length; i += 1) {
            const sequence = sequences[i];
            if (sequence.indexOf(value) !== -1 || characters(sequence).reverse().join("").indexOf(value) !== -1) {
                return true;
            }
        }
        return new Set(characters(value)).size < 4;
    }

    function containsIdentity(password, identity) {
        const value = lower(password);
        const parts = [];
        ["username", "email"].forEach(function (field) {
            const text = lower(identity && identity[field]).trim();
            if (text) {
                parts.push(text.split("@")[0]);
            }
        });
        lower(identity && identity.display_name).split(/[^\p{L}\p{N}]+/u).forEach(function (word) {
            if (characters(word).length >= 4) {
                parts.push(word);
            }
        });
        return parts.some(function (part) {
            return characters(part).length >= 3 && value.indexOf(part) !== -1;
        });
    }

    /** The same rules the server enforces in auth_password_problems(). */
    function passwordChecks(password, confirm, policy, identity) {
        const rules = Object.assign({}, FALLBACK_POLICY, policy || {});
        const length = characters(password).length;
        return {
            length: length >= rules.min_length && length <= rules.max_length,
            mix: /\p{L}/u.test(password) && /\p{N}/u.test(password),
            common: password !== "" && !isCommonPassword(password, rules),
            identity: password !== "" && !containsIdentity(password, identity),
            match: password !== "" && password === confirm,
        };
    }

    function passwordStrength(password, checks) {
        if (!password) {
            return { score: 0, label: "Password strength" };
        }
        let score = 0;
        if (checks.length) score += 1;
        if (checks.mix) score += 1;
        if (checks.common && checks.identity) score += 1;
        const varied = /[a-z]/.test(password) && /[A-Z]/.test(password) && /\d/.test(password) && /[^A-Za-z0-9]/.test(password);
        if (checks.length && (characters(password).length >= 14 || varied)) score += 1;
        if (!checks.common || !checks.identity) {
            score = Math.min(score, 1);
        }
        const labels = ["Too weak", "Weak", "Fair", "Good", "Strong"];
        return { score: score, label: labels[score] };
    }

    const RULE_MESSAGES = {
        length: "Use at least 10 characters.",
        mix: "Include at least one letter and one number.",
        common: "This password is too common or easy to guess.",
        identity: "Don't use your username or name in your password.",
        match: "The two passwords don't match.",
    };

    /**
     * Live checklist + strength meter for a new-password form.
     * options: { input, confirm, meter, label, rules, getPolicy(), getIdentity() }
     */
    function bindPasswordFeedback(options) {
        function update() {
            const password = options.input.value;
            const checks = passwordChecks(password, options.confirm.value, options.getPolicy(), options.getIdentity());
            const strength = passwordStrength(password, checks);
            options.meter.dataset.score = String(strength.score);
            options.label.textContent = password ? "Strength: " + strength.label : "Password strength";
            options.rules.querySelectorAll("[data-rule]").forEach(function (item) {
                const rule = item.getAttribute("data-rule");
                const touched = rule === "match" ? options.confirm.value !== "" : password !== "";
                item.dataset.state = !touched ? "idle" : (checks[rule] ? "met" : "unmet");
            });
            return checks;
        }
        options.input.addEventListener("input", update);
        options.confirm.addEventListener("input", update);
        update();
        return {
            update: update,
            firstProblem: function () {
                const checks = update();
                const order = ["length", "mix", "common", "identity", "match"];
                for (let i = 0; i < order.length; i += 1) {
                    if (!checks[order[i]]) {
                        return RULE_MESSAGES[order[i]];
                    }
                }
                return "";
            },
        };
    }

    /** Show/hide buttons: <button data-password-toggle="inputId">. */
    function bindPasswordToggles(root) {
        root.querySelectorAll("[data-password-toggle]").forEach(function (button) {
            const input = document.getElementById(button.getAttribute("data-password-toggle"));
            if (!input || button.dataset.bound) {
                return;
            }
            button.dataset.bound = "1";
            button.addEventListener("click", function () {
                const willShow = input.type === "password";
                input.type = willShow ? "text" : "password";
                button.setAttribute("aria-pressed", String(willShow));
                button.setAttribute("aria-label", willShow ? "Hide password" : "Show password");
                button.classList.toggle("is-visible", willShow);
                input.focus({ preventScroll: true });
            });
        });
    }

    /**
     * One real input (so paste and one-time-code autofill work) drawn as six boxes.
     * Calls onComplete(code) when six digits are entered.
     */
    function bindOtpInput(input, slots, onComplete) {
        const boxes = Array.from(slots.children);
        function render() {
            const value = input.value;
            const focused = document.activeElement === input;
            boxes.forEach(function (box, index) {
                box.textContent = value.charAt(index);
                box.classList.toggle("is-filled", index < value.length);
                box.classList.toggle("is-active", focused && index === Math.min(value.length, boxes.length - 1));
            });
        }
        function keepCaretAtEnd() {
            const end = input.value.length;
            try {
                input.setSelectionRange(end, end);
            } catch (error) {}
        }
        input.addEventListener("input", function () {
            const digits = input.value.replace(/\D/g, "").slice(0, boxes.length);
            if (digits !== input.value) {
                input.value = digits;
            }
            keepCaretAtEnd();
            render();
            slots.parentElement.classList.remove("is-invalid");
            if (digits.length === boxes.length && typeof onComplete === "function") {
                onComplete(digits);
            }
        });
        ["focus", "blur", "click", "keyup"].forEach(function (name) {
            input.addEventListener(name, function () {
                keepCaretAtEnd();
                render();
            });
        });
        render();
        return {
            value: function () { return input.value; },
            clear: function () {
                input.value = "";
                render();
            },
            invalid: function () {
                slots.parentElement.classList.remove("is-invalid");
                void slots.parentElement.offsetWidth;
                slots.parentElement.classList.add("is-invalid");
                input.value = "";
                render();
            },
            focus: function () {
                input.focus({ preventScroll: true });
                render();
            },
        };
    }

    let qrLibrary = null;
    function loadQrLibrary() {
        if (window.QRCode) {
            return Promise.resolve(true);
        }
        if (!qrLibrary) {
            qrLibrary = new Promise(function (resolve) {
                const tag = document.createElement("script");
                tag.src = QR_LIBRARY_URL;
                tag.async = true;
                tag.onload = function () { resolve(Boolean(window.QRCode)); };
                tag.onerror = function () {
                    qrLibrary = null;
                    resolve(false);
                };
                document.head.appendChild(tag);
            });
        }
        return qrLibrary;
    }

    /** Draws a QR code; resolves false when the library cannot load (offline). */
    function renderQr(container, text) {
        container.replaceChildren();
        return loadQrLibrary().then(function (ok) {
            if (!ok) {
                return false;
            }
            new window.QRCode(container, {
                text: text,
                width: 176,
                height: 176,
                colorDark: "#0b1f3a",
                colorLight: "#ffffff",
                correctLevel: window.QRCode.CorrectLevel.M,
            });
            container.removeAttribute("title");
            return true;
        });
    }

    /** Copies text; falls back to execCommand on plain-HTTP pages where the Clipboard API is missing. */
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return legacyCopy(text); });
        }
        return Promise.resolve(legacyCopy(text));
    }

    function legacyCopy(text) {
        const area = document.createElement("textarea");
        area.value = text;
        area.setAttribute("readonly", "");
        area.style.position = "fixed";
        area.style.top = "-1000px";
        document.body.appendChild(area);
        area.select();
        let copied = false;
        try {
            copied = document.execCommand("copy");
        } catch (error) {
            copied = false;
        }
        area.remove();
        return copied;
    }

    function downloadText(filename, text) {
        const blob = new Blob([text], { type: "text/plain;charset=utf-8" });
        const link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        window.setTimeout(function () {
            URL.revokeObjectURL(link.href);
            link.remove();
        }, 1000);
    }

    function backupCodesText(codes, account) {
        return [
            "ISATU Visitor Management System - backup codes",
            "Account: " + account,
            "Created: " + new Date().toLocaleString(),
            "",
            "Each code works once if you can't use your authenticator app.",
            "",
        ].concat(codes.map(function (code, index) { return (index + 1) + ". " + code; })).join("\r\n") + "\r\n";
    }

    /** "9:05" or "1:02:09". */
    function formatCountdown(totalSeconds) {
        const seconds = Math.max(0, Math.round(totalSeconds));
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const rest = String(seconds % 60).padStart(2, "0");
        return hours > 0 ? hours + ":" + String(minutes).padStart(2, "0") + ":" + rest : minutes + ":" + rest;
    }

    /** "10 minutes", "1 hour 20 minutes". */
    function formatMinutes(totalMinutes) {
        const minutes = Math.max(1, Math.round(totalMinutes));
        const hours = Math.floor(minutes / 60);
        const rest = minutes % 60;
        const parts = [];
        if (hours) parts.push(hours + (hours === 1 ? " hour" : " hours"));
        if (rest) parts.push(rest + (rest === 1 ? " minute" : " minutes"));
        return parts.join(" ");
    }

    function parseServerDate(value) {
        if (!value) {
            return null;
        }
        const date = new Date(String(value).replace(" ", "T"));
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function formatDateTime(value) {
        const date = parseServerDate(value);
        if (!date) {
            return "—";
        }
        return new Intl.DateTimeFormat(undefined, { month: "short", day: "numeric", year: "numeric", hour: "numeric", minute: "2-digit" }).format(date);
    }

    function formatRelative(value) {
        const date = parseServerDate(value);
        if (!date) {
            return "never";
        }
        const seconds = Math.round((Date.now() - date.getTime()) / 1000);
        if (seconds < 60) return "just now";
        const minutes = Math.round(seconds / 60);
        if (minutes < 60) return minutes + (minutes === 1 ? " minute ago" : " minutes ago");
        const hours = Math.round(minutes / 60);
        if (hours < 24) return hours + (hours === 1 ? " hour ago" : " hours ago");
        const days = Math.round(hours / 24);
        if (days < 30) return days + (days === 1 ? " day ago" : " days ago");
        return formatDateTime(value);
    }

    function formatIp(ip) {
        if (!ip) {
            return "unknown address";
        }
        return ip === "::1" || ip === "127.0.0.1" ? "localhost" : ip;
    }

    /** Plain-language reason for an entry in the sign-in history. */
    function describeAttempt(attempt) {
        const reasons = {
            "password": "Signed in",
            "password+authenticator": "Signed in with authenticator code",
            "password+backup_code": "Signed in with a backup code",
            "password+trusted_device": "Signed in on a trusted browser",
            "confirmed_password": "Confirmed identity with password",
            "confirmed_authenticator": "Confirmed identity with authenticator",
            "wrong_password": "Wrong password",
            "unknown_account": "Unknown username",
            "suspended": "Suspended account",
            "wrong_code": "Wrong authenticator code",
            "wrong_backup_code": "Wrong or used backup code",
            "setup_wrong_code": "Wrong code during setup",
            "temporary_password_expired": "Expired temporary password",
        };
        return reasons[attempt.reason] || (attempt.outcome === "success" ? "Signed in" : "Failed");
    }

    window.PhoneTrackerAuthUI = {
        passwordChecks: passwordChecks,
        passwordStrength: passwordStrength,
        bindPasswordFeedback: bindPasswordFeedback,
        bindPasswordToggles: bindPasswordToggles,
        bindOtpInput: bindOtpInput,
        renderQr: renderQr,
        copyText: copyText,
        downloadText: downloadText,
        backupCodesText: backupCodesText,
        formatCountdown: formatCountdown,
        formatMinutes: formatMinutes,
        formatDateTime: formatDateTime,
        formatRelative: formatRelative,
        formatIp: formatIp,
        describeAttempt: describeAttempt,
    };
})();
