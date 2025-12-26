(function(window, document, $){
    'use strict';
    // Toggle dev-only meta notices in chat UI
    var SHOW_TECH_META = false;
    var config = window.BKJA || window.bkja_vars || {};
    var nonceRefreshRequest = null;
    var sessionId = '';
    var guestUsageKey = '';
    var guestUsage = null;
    var guestLimitResponder = null;

    function storageUsable(){
        if(typeof localStorage === 'undefined'){
            return false;
        }
        try {
            var testKey = '__bkja_test__';
            localStorage.setItem(testKey, testKey);
            localStorage.removeItem(testKey);
            return true;
        } catch (storageError) {
            return false;
        }
    }

    var localStorageAvailable = storageUsable();
    var guestUsageStorageAvailable = localStorageAvailable;

    function generateSessionId(){
        return 'bkja_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    }

    function readCookie(name){
        var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()\[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : '';
    }

    function writeCookie(name, val, days){
        var exp = new Date();
        exp.setTime(exp.getTime() + (days*24*60*60*1000));
        document.cookie = name + '=' + encodeURIComponent(val || '') + '; expires=' + exp.toUTCString() + '; path=/; SameSite=Lax';
    }

    function persistSessionValue(value){
        if(!value){
            return;
        }
        if(localStorageAvailable){
            try {
                localStorage.setItem('bkja_session', value);
            } catch(storageError){
                localStorageAvailable = false;
                guestUsageStorageAvailable = false;
            }
        }
        writeCookie('bkja_session', value, 30);
    }

    function assignActiveSession(value, options){
        if(typeof value !== 'string'){
            return;
        }
        var trimmed = $.trim(value);
        if(!trimmed || trimmed.length <= 10){
            return;
        }
        var sameSession = sessionId === trimmed;
        sessionId = trimmed;
        config.session = trimmed;
        config.server_session = trimmed;
        if(window && window.BKJA){
            window.BKJA.session = trimmed;
            window.BKJA.server_session = trimmed;
        }
        persistSessionValue(trimmed);
        guestUsageKey = 'bkja_guest_usage_v2_' + trimmed;
        if(!options || options.reloadUsage !== false){
            if(guestUsageStorageAvailable){
                guestUsage = loadGuestUsage();
            } else if(!sameSession){
                guestUsage = { count: 0, updated: nowMs() };
            }
        }
    }

    (function syncSession(){
        var sess = readCookie('bkja_session') || '';
        if(!sess && localStorageAvailable){
            try {
                var stored = localStorage.getItem('bkja_session');
                if(stored){
                    sess = stored;
                }
            } catch(storageError){
                localStorageAvailable = false;
                guestUsageStorageAvailable = false;
            }
        }
        if(window.BKJA && typeof window.BKJA.server_session === 'string' && window.BKJA.server_session.length > 10){
            sess = window.BKJA.server_session;
        } else if(config && typeof config.server_session === 'string' && config.server_session.length > 10){
            sess = config.server_session;
        }
        if(!sess){
            sess = generateSessionId();
        }
        if(localStorageAvailable){
            try {
                localStorage.setItem('bkja_session', sess);
            } catch(storageError){
                localStorageAvailable = false;
                guestUsageStorageAvailable = false;
            }
        }
        writeCookie('bkja_session', sess, 30);
        assignActiveSession(sess);
    })();

    function ensureSession(){
        if(!sessionId || sessionId.length <= 10){
            var sess = readCookie('bkja_session') || '';
            if(!sess && localStorageAvailable){
                try {
                    var stored = localStorage.getItem('bkja_session');
                    if(stored){
                        sess = stored;
                    }
                } catch(storageError){
                    localStorageAvailable = false;
                    guestUsageStorageAvailable = false;
                }
            }
            if(config && typeof config.server_session === 'string' && config.server_session.length > 10){
                sess = config.server_session;
            }
            if(!sess){
                sess = generateSessionId();
            }
            assignActiveSession(sess);
        }
        return sessionId;
    }

    function syncSessionFromPayload(payload){
        if(!payload){
            return;
        }
        var nextSession = '';
        if(payload.server_session && typeof payload.server_session === 'string'){
            nextSession = payload.server_session;
        } else if(payload.guest_session && typeof payload.guest_session === 'string'){
            nextSession = payload.guest_session;
        }
        if(nextSession && nextSession.length > 10){
            assignActiveSession(nextSession);
        }
    }

    function nowMs(){
        if(typeof Date !== 'undefined' && Date.now){
            return Date.now();
        }
        return new Date().getTime();
    }

    function loadGuestUsage(){
        var usage = { count: 0, updated: nowMs() };
        if(!guestUsageKey){
            return usage;
        }
        if(guestUsageStorageAvailable){
            try {
                var raw = localStorage.getItem(guestUsageKey);
                if(raw){
                    var parsed = JSON.parse(raw);
                    if(parsed && typeof parsed.count === 'number' && parsed.count >= 0){
                        usage.count = parsed.count;
                    }
                    if(parsed && typeof parsed.updated === 'number' && parsed.updated > 0){
                        usage.updated = parsed.updated;
                    }
                }
            } catch(storageError){
                guestUsageStorageAvailable = false;
            }
        }
        if(!usage.updated || usage.updated <= 0){
            usage.updated = nowMs();
        }
        var maxAgeMs = 24 * 60 * 60 * 1000;
        if(usage.updated && nowMs() - usage.updated > maxAgeMs){
            usage.count = 0;
            usage.updated = nowMs();
        }
        return usage;
    }

    function persistGuestUsage(){
        if(!guestUsageStorageAvailable || !guestUsageKey){
            return;
        }
        try {
            localStorage.setItem(guestUsageKey, JSON.stringify({
                count: guestUsage && typeof guestUsage.count === 'number' ? guestUsage.count : 0,
                updated: guestUsage && typeof guestUsage.updated === 'number' ? guestUsage.updated : nowMs()
            }));
        } catch(storageError){
            guestUsageStorageAvailable = false;
        }
    }

    function setGuestUsageCount(value){
        var parsed = parseInt(value, 10);
        if(isNaN(parsed) || parsed < 0){
            parsed = 0;
        }
        if(!guestUsage){
            guestUsage = { count: parsed, updated: nowMs() };
        } else {
            guestUsage.count = parsed;
            guestUsage.updated = nowMs();
        }
        persistGuestUsage();
    }

    function incrementGuestUsage(){
        setGuestUsageCount(getGuestUsageCount() + 1);
    }

    function getGuestUsageCount(){
        if(guestUsage && typeof guestUsage.count === 'number' && guestUsage.count >= 0){
            return guestUsage.count;
        }
        return 0;
    }

    function getGuestLimit(){
        var limit = parseInt(config.free_limit, 10);
        if(isNaN(limit) || limit < 0){
            return 0;
        }
        return limit;
    }

    function updateGuestLimitFromServer(value){
        var parsed = parseInt(value, 10);
        if(!isNaN(parsed)){
            config.free_limit = parsed;
        }
    }

    function extractGuestLimitPayload(res){
        if(!res){
            return null;
        }
        var payload = null;
        if(res.data && typeof res.data === 'object'){
            payload = res.data;
        } else if(typeof res === 'object'){
            payload = res;
        }
        if(payload && payload.error === 'guest_limit'){
            return payload;
        }
        return null;
    }

    function defaultLoginUrl(){
        var url = config.login_url;
        if(typeof url !== 'string' || !url.length){
            url = '/wp-login.php';
        }
        return url;
    }

    function canGuestSendMessage(){
        if(isTruthy(config.is_logged_in)){
            return true;
        }
        var limit = getGuestLimit();
        if(limit <= 0){
            return false;
        }
        return getGuestUsageCount() < limit;
    }

    function refreshNonce(){
        if(nonceRefreshRequest){
            return nonceRefreshRequest;
        }
        var deferred = $.Deferred();
        nonceRefreshRequest = deferred;
        $.post(config.ajax_url, {
            action: 'bkja_refresh_nonce'
        }).done(function(res){
            var limitPayload = extractGuestLimitPayload(res);
            if(limitPayload && typeof guestLimitResponder === 'function'){
                guestLimitResponder(limitPayload);
                deferred.resolve(false);
                return;
            }
            if(res && res.success && res.data && res.data.nonce){
                config.nonce = res.data.nonce;
                if(res.data.hasOwnProperty('is_logged_in')){
                    config.is_logged_in = res.data.is_logged_in;
                }
                if(res.data.hasOwnProperty('free_limit')){
                    config.free_limit = res.data.free_limit;
                }
                if(res.data.hasOwnProperty('login_url') && res.data.login_url){
                    config.login_url = res.data.login_url;
                }
                if(res.data){
                    syncSessionFromPayload(res.data);
                }
                deferred.resolve(true);
            } else {
                deferred.resolve(false);
            }
        }).fail(function(){
            deferred.resolve(false);
        }).always(function(){
            nonceRefreshRequest = null;
        });
        return deferred.promise();
    }

    function ajaxWithNonce(params, options){
        options = options || {};
        ensureSession();
        var deferred = $.Deferred();
        var payload = $.extend({}, params, { nonce: config.nonce });
        $.ajax({
            url: config.ajax_url,
            method: 'POST',
            dataType: options.dataType || 'json',
            headers: {
                'X-BKJA-Session': sessionId
            },
            data: payload
        }).done(function(res, textStatus, jqXHR){
            if(res && res.data){
                syncSessionFromPayload(res.data);
            }
            var limitPayload = extractGuestLimitPayload(res);
            if(limitPayload && typeof guestLimitResponder === 'function'){
                guestLimitResponder(limitPayload);
                deferred.resolve(res, textStatus, jqXHR);
                return;
            }
            if(res && res.data && res.data.error === 'invalid_nonce' && !options._retry){
                refreshNonce().then(function(success){
                    if(success){
                        var retryOptions = $.extend({}, options, { _retry: true });
                        ajaxWithNonce(params, retryOptions).done(function(){
                            deferred.resolve.apply(deferred, arguments);
                        }).fail(function(){
                            deferred.reject.apply(deferred, arguments);
                        });
                    } else {
                        deferred.reject(res, textStatus, jqXHR);
                    }
                });
            } else {
                deferred.resolve(res, textStatus, jqXHR);
            }
        }).fail(function(jqXHR, textStatus, errorThrown){
            var responseJSON = jqXHR && jqXHR.responseJSON;
            if(responseJSON && responseJSON.data){
                syncSessionFromPayload(responseJSON.data);
            }
            var invalidNonce = jqXHR && jqXHR.status === 403 && responseJSON && responseJSON.data && responseJSON.data.error === 'invalid_nonce';
            if(!invalidNonce && responseJSON && responseJSON.data && responseJSON.data.error === 'invalid_nonce'){
                invalidNonce = true;
            }
            if(invalidNonce && !options._retry){
                refreshNonce().then(function(success){
                    if(success){
                        var retryOptions = $.extend({}, options, { _retry: true });
                        ajaxWithNonce(params, retryOptions).done(function(){
                            deferred.resolve.apply(deferred, arguments);
                        }).fail(function(){
                            deferred.reject.apply(deferred, arguments);
                        });
                    } else {
                        deferred.reject(jqXHR, textStatus, errorThrown);
                    }
                });
            } else {
                deferred.reject(jqXHR, textStatus, errorThrown);
            }
        });
        return deferred.promise();
    }

    function isTruthy(value){
        if(value === undefined || value === null){
            return false;
        }
        if(typeof value === 'string'){
            var normalized = value.toLowerCase();
            return normalized === '1' || normalized === 'true' || normalized === 'yes';
        }
        if(typeof value === 'number'){
            return value === 1;
        }
        if(typeof value === 'boolean'){
            return value;
        }
        return false;
    }
    if(!config.ajax_url){
        if(typeof window.ajaxurl !== 'undefined'){
            config.ajax_url = window.ajaxurl;
        } else {
            config.ajax_url = '/wp-admin/admin-ajax.php';
        }
    }
    if(!config.nonce && window.bkja_vars && window.bkja_vars.nonce){
        config.nonce = window.bkja_vars.nonce;
    }
    if(typeof config.is_logged_in === 'undefined'){
        config.is_logged_in = 0;
    }
    if(typeof config.free_limit === 'undefined'){
        config.free_limit = 0;
    }
    if(!config.login_url && window.bkja_vars && window.bkja_vars.login_url){
        config.login_url = window.bkja_vars.login_url;
    }
    if(!config.login_url){
        config.login_url = '/wp-login.php';
    }
    function bkja_log(){ try{ console.log.apply(console, ['%cBKJA','color:#fff;background:#0b79d0;padding:2px 6px;border-radius:3px;'].concat(Array.prototype.slice.call(arguments))); }catch(e){} }

    $(function(){
        function ensureViewportFitCover(){
            try {
                var meta = document.querySelector('meta[name="viewport"]');
                var desired = 'viewport-fit=cover';
                if(!meta){
                    meta = document.createElement('meta');
                    meta.name = 'viewport';
                    meta.content = 'width=device-width, initial-scale=1, ' + desired;
                    document.head.appendChild(meta);
                    return;
                }
                var current = meta.getAttribute('content') || '';
                if(/viewport-fit\s*=\s*cover/i.test(current)){
                    return;
                }
                if(current.trim().length){
                    if(/[,;]\s*$/.test(current)){
                        current += desired;
                    } else {
                        current += ', ' + desired;
                    }
                } else {
                    current = 'width=device-width, initial-scale=1, ' + desired;
                }
                meta.setAttribute('content', current);
            } catch (err) {
                // ignore
            }
        }

        ensureViewportFitCover();

        var rootEl = document.documentElement;
        var quickActionsEnabled = isTruthy(config.enable_quick_actions);
        var feedbackEnabled = isTruthy(config.enable_feedback);
        var mobileQuery = window.matchMedia ? window.matchMedia('(max-width: 768px)') : { matches: false };
        var baseSafeAreaBottom = null;
        var baseSafeAreaTop = null;

        function updateMobileViewportHeight(){
            if(!mobileQuery.matches){
                rootEl.style.removeProperty('--bkja-mobile-panel-height');
                rootEl.style.removeProperty('--bkja-keyboard-offset');
                rootEl.style.removeProperty('--bkja-safe-area-top');
                rootEl.style.removeProperty('--bkja-safe-area-bottom');
                return;
            }
            var viewport = window.visualViewport;
            var layoutHeight = window.innerHeight || document.documentElement.clientHeight || document.body.clientHeight;
            var viewportHeight = viewport ? viewport.height : layoutHeight;
            var offsetTop = viewport ? Math.max(viewport.offsetTop, 0) : 0;
            var bottomInset = viewport ? Math.max(0, layoutHeight - (viewportHeight + offsetTop)) : 0;

            if(baseSafeAreaBottom === null || bottomInset < baseSafeAreaBottom + 1){
                baseSafeAreaBottom = bottomInset;
            }
            if(baseSafeAreaTop === null || offsetTop < baseSafeAreaTop + 1){
                baseSafeAreaTop = offsetTop;
            }

            var keyboardOffset = Math.max(0, bottomInset - (baseSafeAreaBottom || 0));

            rootEl.style.setProperty('--bkja-mobile-panel-height', viewportHeight + 'px');
            rootEl.style.setProperty('--bkja-keyboard-offset', keyboardOffset + 'px');
            rootEl.style.setProperty('--bkja-safe-area-top', (baseSafeAreaTop || 0) + 'px');
            rootEl.style.setProperty('--bkja-safe-area-bottom', (baseSafeAreaBottom || 0) + 'px');
        }

        updateMobileViewportHeight();

        if(window.visualViewport && window.visualViewport.addEventListener){
            window.visualViewport.addEventListener('resize', updateMobileViewportHeight);
            window.visualViewport.addEventListener('scroll', updateMobileViewportHeight);
        }

        window.addEventListener('resize', updateMobileViewportHeight);
        window.addEventListener('orientationchange', function(){
            baseSafeAreaBottom = null;
            baseSafeAreaTop = null;
            setTimeout(updateMobileViewportHeight, 150);
        });

        if(mobileQuery && mobileQuery.addEventListener){
            mobileQuery.addEventListener('change', function(){
                baseSafeAreaBottom = null;
                baseSafeAreaTop = null;
                updateMobileViewportHeight();
            });
        } else if(mobileQuery && mobileQuery.addListener){
            mobileQuery.addListener(function(){
                baseSafeAreaBottom = null;
                baseSafeAreaTop = null;
                updateMobileViewportHeight();
            });
        }

        // chat submit & quick items
        var $form = $('#bkja-chat-form');
        var $input = $('#bkja-user-message');
        var $messages = $('.bkja-messages');

        if($input.length){
            $input.on('focus', function(){
                setTimeout(function(){
                    updateMobileViewportHeight();
                    if($messages && $messages.length){
                        $messages.scrollTop($messages.prop('scrollHeight'));
                    }
                }, 120);
            });
            $input.on('blur', function(){
                setTimeout(updateMobileViewportHeight, 150);
            });
        }
        var lastKnownJobTitle = '';
        var lastKnownJobTitleId = null;
        var lastKnownGroupKey = '';
        var lastKnownJobSlug = '';
        var lastReplyMeta = {};
        window.lastReplyMeta = lastReplyMeta;
        var categoryDisplayNames = {};
        var personalityFlow = {
            active: false,
            awaitingResult: false,
            step: 0,
            jobTitle: '',
            answers: [],
            questions: [
                { id: 'interests', text: 'بیشتر به چه نوع کارها یا فعالیت‌هایی علاقه داری؟' },
                { id: 'environment', text: 'چه محیط کاری (مثلاً کارگاهی، اداری، تیمی یا مستقل) برایت انگیزه‌بخش‌تر است؟' },
                { id: 'skills', text: 'مهم‌ترین مهارت یا نقطه قوتی که در کار داری چیه؟' },
                { id: 'stress', text: 'وقتی با شرایط پرتنش یا غیرقابل‌پیش‌بینی مواجه می‌شی چطور واکنش می‌دی؟' }
            ]
        };

        ensureSession();
        if(!guestUsage){
            guestUsage = loadGuestUsage();
        }

        function esc(s){ return $('<div/>').text(s).html(); }
        function formatMessage(text){
            if(text === null || text === undefined){ text = ''; }
            if(typeof text !== 'string'){ text = String(text); }
            return esc(text).replace(/\n/g,'<br>');
        }
        function pushUser(text){
            var $m = $('<div class="bkja-bubble user"></div>').html(formatMessage(text));
            $messages.append($m);
            $messages.scrollTop($messages.prop('scrollHeight'));
        }
        function pushBot(text, opts){
            opts = opts || {};
            if(text === null || text === undefined){ text = ''; }
            if(typeof text !== 'string'){ text = String(text); }
            var $typing = $('<div class="bkja-typing"><span class="dot"></span><span class="dot"></span><span class="dot"></span></div>');
            $messages.append($typing);
            $messages.scrollTop($messages.prop('scrollHeight'));
            setTimeout(function(){
                $typing.remove();
                var $m = $('<div class="bkja-bubble bot"></div>');
                var $span = $('<span></span>');
                $m.append($span);
                $messages.append($m);
                $messages.scrollTop($messages.prop('scrollHeight'));
                var i = 0;
                var interval = setInterval(function(){
                    i++;
                    $span.html(formatMessage(text.substring(0,i)));
                    $messages.scrollTop($messages.prop('scrollHeight'));
                    if(i >= text.length){
                        clearInterval(interval);
                        if(typeof opts.onComplete === 'function'){
                            opts.onComplete($m);
                        }
                    }
                }, 18);
            }, 1200);
        }
        function pushBotHtml(html){
            var content = html;
            if(typeof content === 'string' && content.indexOf('<') === -1){
                content = formatMessage(content);
            }
            var $m = $('<div class="bkja-bubble bot"></div>').html(content);
            $messages.append($m);
            $messages.scrollTop($messages.prop('scrollHeight'));
        }

        function addSystemMessage(text){
            var safeText = typeof text === 'string' ? text : String(text || '');
            pushBotHtml('<div class="bkja-system-message">' + formatMessage(safeText) + '</div>');
        }

        function handleGuestLimit(loginUrl, limit, message){
            var $input = $('#bkja-user-message');
            var $send  = $('#bkja-send');
            var parsedLimit = typeof limit === 'number' ? limit : parseInt(limit, 10);
            if(isNaN(parsedLimit)){
                var fallback = parseInt(config.free_limit, 10);
                if(isNaN(fallback)){
                    fallback = 0;
                }
                parsedLimit = fallback;
            }
            parsedLimit = Math.max(0, parsedLimit);
            var loginHref = typeof loginUrl === 'string' && loginUrl.length ? loginUrl : defaultLoginUrl();

            var notice = '';
            if(typeof message === 'string' && message.trim().length){
                notice = message.trim();
            }
            if(!notice){
                notice = 'ظرفیت پیام‌های رایگان امروز شما به پایان رسیده است. برای ادامه گفتگو لطفاً وارد شوید یا عضویت خود را ارتقا دهید.';
            }

            var detailText = '';
            if(parsedLimit > 0){
                detailText = ' سهمیه امروز شامل ' + esc(String(parsedLimit)) + ' پیام رایگان است.';
            }

            $input.prop('disabled', true);
            $send.prop('disabled', true);

            pushBotHtml(
                '<div style="color:#d32f2f;font-weight:700;margin-top:8px;">' +
                esc(notice) +
                (detailText ? '<div style="font-weight:400;margin-top:6px;">' + esc(detailText) + '</div>' : '') +
                '<a href="' + esc(loginHref) + '" style="display:inline-block;margin-top:8px;text-decoration:underline;">ورود یا ثبت‌نام</a>' +
                '</div>'
            );
        }

        function handleGuestLimitExceeded(payload){
            if(payload){
                if(payload.server_session && typeof payload.server_session === 'string' && payload.server_session.length > 10){
                    assignActiveSession(payload.server_session, { reloadUsage: false });
                }
                syncSessionFromPayload(payload);
            }

            var payloadLimit = payload && Object.prototype.hasOwnProperty.call(payload, 'guest_message_limit')
                ? payload.guest_message_limit
                : (payload && Object.prototype.hasOwnProperty.call(payload, 'limit') ? payload.limit : undefined);

            if(typeof payloadLimit !== 'undefined'){
                updateGuestLimitFromServer(payloadLimit);
            }

            var limitValue;
            if(typeof payloadLimit !== 'undefined'){
                limitValue = parseInt(payloadLimit, 10);
            }
            if(isNaN(limitValue)){
                limitValue = getGuestLimit();
            }

            var payloadCount = payload && Object.prototype.hasOwnProperty.call(payload, 'guest_message_count')
                ? payload.guest_message_count
                : (payload && Object.prototype.hasOwnProperty.call(payload, 'count') ? payload.count : undefined);

            if(typeof payloadCount !== 'undefined'){
                var parsedCount = parseInt(payloadCount, 10);
                if(!isNaN(parsedCount)){
                    setGuestUsageCount(Math.max(0, parsedCount));
                }
            } else if(typeof limitValue === 'number' && !isNaN(limitValue)){
                setGuestUsageCount(Math.max(0, limitValue));
            }

            if(payload && payload.login_url){
                config.login_url = payload.login_url;
            }

            var loginTarget = payload && payload.login_url ? payload.login_url : config.login_url;
            var limitMessage = '';
            if(payload && Object.prototype.hasOwnProperty.call(payload, 'message')){
                limitMessage = String(payload.message || '');
            }
            handleGuestLimit(loginTarget, limitValue, limitMessage);
        }

        guestLimitResponder = handleGuestLimitExceeded;


        function maybeAnnounceGuestLimitReached(){
            if(isTruthy(config.is_logged_in)){
                return;
            }
            var limit = getGuestLimit();
            if(limit <= 0){
                return;
            }
            var count = getGuestUsageCount();
            if(count < limit){
                return;
            }
            handleGuestLimitExceeded({
                limit: limit,
                count: count,
                login_url: config.login_url
            });
        }

        function mapCategoryToHumanName(category){
            if(category === null || category === undefined){
                return '';
            }
            var key = String(category);
            var lookup = key.toLowerCase();
            if(categoryDisplayNames.hasOwnProperty(lookup)){
                return categoryDisplayNames[lookup];
            }
            if(categoryDisplayNames.hasOwnProperty(key)){
                return categoryDisplayNames[key];
            }
            if(lookup === 'generic'){
                return '';
            }
            return key.replace(/[-_]+/g, ' ').trim();
        }

        function cleanJobHint(value){
            if(value === null || value === undefined){
                return '';
            }
            var text = $.trim(String(value));
            if(!text || text === 'این حوزه'){
                return '';
            }
            return text;
        }

        function buildQuickActionsForMessage($message) {
            if(!quickActionsEnabled){
                return null;
            }
            var el = $message;
            if ($message && $message.jquery) {
                el = $message.get(0);
            }
            if (!el) {
                return null;
            }

            var dataset = el.dataset || {};
            var cat  = dataset.category || el.getAttribute('data-category') || '';
            var job  = dataset.jobTitle || el.getAttribute('data-job-title') || '';
            var slug = dataset.jobSlug  || el.getAttribute('data-job-slug')  || '';

            if((!cat || !job || !slug) && lastReplyMeta && typeof lastReplyMeta === 'object'){
                if(!cat && lastReplyMeta.category){
                    cat = String(lastReplyMeta.category);
                }
                if(!job && lastReplyMeta.job_title){
                    job = String(lastReplyMeta.job_title);
                }
                if(!slug && lastReplyMeta.job_slug){
                    slug = String(lastReplyMeta.job_slug);
                }
            }

            var wrap = document.createElement('div');
            wrap.className = 'bkja-quick-actions';

            var btnNext = document.createElement('button');
            btnNext.type = 'button';
            btnNext.className = 'bkja-btn-next';
            btnNext.textContent = 'قدم بعدی منطقی';
            btnNext.addEventListener('click', function(){
                var label = job || 'این حوزه';
                var followup = 'به من کمک کن بدانم قدم بعدی منطقی برای تحقیق بیشتر درباره ' + label + ' چیست.';
                dispatchUserMessage(followup, { category: cat, jobTitle: job, jobSlug: slug });
            });

            wrap.appendChild(btnNext);
            return wrap;
        }

        function applyAssistantMeta($message, meta){
            if(!$message || !$message.length){
                return;
            }
            var data = meta || {};
            var el = $message.get(0);
            if(!el){
                return;
            }
            var categoryValue = data.category ? String(data.category) : '';
            var jobTitleValue = data.job_title ? String(data.job_title) : '';
            var jobSlugValue = data.job_slug ? String(data.job_slug) : '';
            if(data && typeof data.job_title === 'string' && data.job_title.trim()){
                lastKnownJobTitle = data.job_title.trim();
            }
            if(data && data.job_title_id !== undefined && data.job_title_id !== null){
                var parsedJobId = parseInt(data.job_title_id, 10);
                if(!isNaN(parsedJobId)){
                    lastKnownJobTitleId = parsedJobId;
                }
            }
            if(data.group_key){
                lastKnownGroupKey = String(data.group_key);
            }
            if(jobSlugValue){
                lastKnownJobSlug = jobSlugValue;
            }

            function appendJobMetaNote(){
                $message.find('.bkja-job-meta-note').remove();
                if(!data.used_job_stats){
                    return;
                }
                var count = parseInt(data.job_report_count, 10);
                if(isNaN(count) || count <= 0){
                    return;
                }
                var title = jobTitleValue || data.jobTitle || '';
                if(!title){
                    return;
                }
                var noteText = 'این پاسخ بر اساس ' + count + ' گزارش واقعی کاربران دربارهٔ «' + esc(title) + '» است.';
                var $note = $('<div class="bkja-job-meta-note"></div>').text(noteText);
                $note.css({ fontSize: '12px', color: '#555', marginTop: '6px' });
                $message.append($note);
            }

            if(el.dataset){
                el.dataset.category = categoryValue;
                el.dataset.jobTitle = jobTitleValue;
                el.dataset.jobSlug = jobSlugValue;
            }
            el.setAttribute('data-category', categoryValue);
            el.setAttribute('data-job-title', jobTitleValue);
            el.setAttribute('data-job-slug', jobSlugValue);

            $message.find('.bkja-quick-actions').remove();
            var actions = buildQuickActionsForMessage(el);
            if(actions && actions.childNodes && actions.childNodes.length){
                $message.append(actions);
            }

            appendJobMetaNote();
        }

        function removeFollowups(){
            $('.bkja-followups').remove();
        }
        window.removeFollowups = removeFollowups;

        function sanitizeSuggestions(list, meta){
            var arr = Array.isArray(list) ? list.slice() : [];
            var job = '';
            if(meta && meta.job_title){
                job = String(meta.job_title).toLowerCase().replace(/\s+/g,'');
            }
            var hasJobContext = !!(meta && (meta.job_title_id || meta.group_key || (Array.isArray(meta.job_title_ids) && meta.job_title_ids.length) || meta.job_title));
            return arr.map(function(entry){
                if(entry === null || entry === undefined){
                    return null;
                }
                var label = '';
                if(typeof entry === 'object'){
                    label = entry.label || entry.action || '';
                } else {
                    label = String(entry);
                }
                label = $.trim(String(label || ''));
                if(!label){
                    return null;
                }
                var lower = label.toLowerCase();
                if((lower.indexOf('مقایسه') !== -1 && lower.indexOf('مشابه') !== -1) && !hasJobContext){
                    return null;
                }
                if(/آرایش|زیبایی|سالن/.test(lower)){
                    if(!job || lower.indexOf(job) === -1){
                        return null;
                    }
                }
                if(typeof entry === 'object'){
                    return Object.assign({}, entry, { label: label });
                }
                return { label: label };
            }).filter(function(entry){ return !!entry; });
        }

        var lastFollowupSignature = '';

        function renderFollowupButtons(items, meta){
            removeFollowups();
            meta = meta || {};
            var hasJobContext = !!(((meta && meta.job_title && String(meta.job_title).trim()) || (lastKnownJobTitle && lastKnownJobTitle.trim())));
            if(!hasJobContext){
                return [];
            }
            var clarificationOptions = Array.isArray(meta.clarification_options) ? meta.clarification_options.slice(0,3) : [];
            var signature = hasJobContext ? String(meta.job_title) + '|' + clarificationOptions.map(function(opt){ return opt && opt.label ? opt.label : String(opt||''); }).join('|') : '';
            if(signature && signature === lastFollowupSignature){
                return [];
            }

            var expCount = null;
            if(typeof meta.job_report_count !== 'undefined' && meta.job_report_count !== null){
                var parsedCount = parseInt(meta.job_report_count, 10);
                if(!isNaN(parsedCount)){
                    expCount = parsedCount;
                }
            }
            var lowData = expCount !== null && expCount >= 0 && expCount < 3;
            var hasAmbiguity = clarificationOptions.length > 0 || (meta.resolved_confidence && meta.resolved_confidence < 0.55);
            var queryIntent = meta.query_intent || '';
            var generalIntents = ['general_exploratory', 'general_high_income', 'compare', 'invest_idea', 'open_question'];
            var isGeneral = !hasJobContext || generalIntents.indexOf(String(queryIntent)) !== -1;
            var isIncomeWithEnoughData = queryIntent === 'job_income' && !hasAmbiguity && !lowData;
            if(isIncomeWithEnoughData){
                return [];
            }

            var shouldShow = hasAmbiguity || lowData || isGeneral;
            if(!shouldShow){
                return [];
            }

            var suggestions = sanitizeSuggestions(items, meta);
            if(!suggestions.length){
                return [];
            }

            var $wrap = $('<div class="bkja-followups" role="group"></div>');
            var followupJobTitle = (meta.job_title && String(meta.job_title).trim()) ? String(meta.job_title).trim() : (lastKnownJobTitle && lastKnownJobTitle.trim() ? String(lastKnownJobTitle).trim() : '');
            var followupJobTitleId = meta.job_title_id ? String(meta.job_title_id) : (lastKnownJobTitleId ? String(lastKnownJobTitleId) : '');
            var nextOffset = (typeof meta.next_offset !== 'undefined' && meta.next_offset !== null) ? meta.next_offset : meta.records_next_offset;
            var hasMoreRecords = false;
            if(typeof meta.has_more !== 'undefined' && meta.has_more !== null){
                hasMoreRecords = !!meta.has_more;
            } else if(typeof meta.records_has_more !== 'undefined' && meta.records_has_more !== null){
                hasMoreRecords = !!meta.records_has_more;
            }

            if(hasAmbiguity){
                clarificationOptions.forEach(function(opt){
                    if(opt === null || opt === undefined){ return; }
                    var label = '';
                    if(typeof opt === 'object'){
                        label = opt.label || opt.job_title || '';
                    } else {
                        label = String(opt);
                    }
                    label = $.trim(String(label || ''));
                    if(!label){ return; }
                    var $btnOpt = $('<button type="button" class="bkja-followup-btn" role="listitem"></button>');
                    $btnOpt.text(label);
                    $btnOpt.attr('data-message', label);
                    $btnOpt.attr('data-job-title', followupJobTitle);
                    $btnOpt.attr('data-job-title-id', followupJobTitleId);
                    if(opt && typeof opt === 'object'){
                        if(opt.group_key){ $btnOpt.attr('data-group-key', String(opt.group_key)); }
                        if(opt.slug){ $btnOpt.attr('data-job-slug', String(opt.slug)); }
                    }
                    $wrap.append($btnOpt);
                });
            }

            if(!hasAmbiguity){
                suggestions.forEach(function(entry){
                    if(entry === null || entry === undefined){ return; }
                    var label = '';
                    var action = '';
                    var offsetVal = null;
                    if(typeof entry === 'object'){
                        label = entry.label || entry.action || '';
                        action = entry.action || entry.label || '';
                        if(entry.offset !== undefined){ offsetVal = entry.offset; }
                    } else {
                        label = String(entry);
                        action = label;
                    }
                    var clean = $.trim(String(label || ''));
                    if(!clean){ return; }
                    var $btn = $('<button type="button" class="bkja-followup-btn" role="listitem"></button>');
                    $btn.text(clean);
                    $btn.attr('data-message', clean);
                    $btn.attr('data-followup-action', action || clean);
                    $btn.attr('data-job-title', followupJobTitle);
                    $btn.attr('data-job-title-id', followupJobTitleId);
                    if(hasJobContext){
                        if(meta.job_slug){ $btn.attr('data-job-slug', String(meta.job_slug)); }
                        if(meta.group_key){ $btn.attr('data-group-key', String(meta.group_key)); }
                    }
                    var offsetToUse = (offsetVal !== null && offsetVal !== undefined) ? offsetVal : nextOffset;
                    if(clean.indexOf('نمایش بیشتر') !== -1 && hasMoreRecords && typeof offsetToUse !== 'undefined'){
                        $btn.attr('data-offset', offsetToUse);
                    }
                    $wrap.append($btn);
                });
            }

            if(!$wrap.children().length){
                return [];
            }

            $messages.append($wrap);
            $messages.scrollTop($messages.prop('scrollHeight'));
            lastFollowupSignature = signature || (hasJobContext ? String(meta.job_title) : '');
            return [];
        }

        // Delegated click handler for dynamically-rendered followup buttons
        document.addEventListener('click', function(e){
            var target = e.target || e.srcElement;
            if(!target){
                return;
            }

            var isFollowupButton = false;
            if(target.classList && target.classList.contains('bkja-followup-btn')){
                isFollowupButton = true;
            } else if(target.closest){
                var maybeBtn = target.closest('.bkja-followup-btn');
                if(maybeBtn){
                    target = maybeBtn;
                    isFollowupButton = true;
                }
            }

            if(!target.closest || !target.closest('.bkja-followups')){
                if(typeof window.removeFollowups === 'function'){
                    window.removeFollowups();
                }
            }

            if(!isFollowupButton){
                return;
            }

            e.preventDefault();

            var followupText = '';
            if(typeof target.getAttribute === 'function'){
                followupText = target.getAttribute('data-message') || '';
            }
            if(!followupText){
                followupText = target.textContent || target.innerText || '';
            }
            followupText = $.trim(String(followupText || ''));
            if(!followupText.length){
                return;
            }

            var opts = {};
            if(typeof target.getAttribute === 'function'){
                var catAttr = target.getAttribute('data-category') || '';
                var jobTitleAttr = target.getAttribute('data-job-title') || '';
                var jobSlugAttr = target.getAttribute('data-job-slug') || '';
                var jobTitleIdAttr = target.getAttribute('data-job-title-id') || '';
                var groupKeyAttr = target.getAttribute('data-group-key') || '';
                var followupActionAttr = target.getAttribute('data-followup-action') || '';
                var offsetAttr = target.getAttribute('data-offset') || '';
                if(catAttr){ opts.category = catAttr; }
                var resolvedJobTitle = jobTitleAttr || lastKnownJobTitle || '';
                if(resolvedJobTitle){ opts.jobTitle = cleanJobHint(resolvedJobTitle); }
                if(jobSlugAttr){ opts.jobSlug = jobSlugAttr; }
                if(jobTitleIdAttr){
                    var parsedId = parseInt(jobTitleIdAttr, 10);
                    if(!isNaN(parsedId) && parsedId > 0){
                        opts.jobTitleId = parsedId;
                    }
                }
                if(!opts.jobTitleId && lastKnownJobTitleId){
                    opts.jobTitleId = lastKnownJobTitleId;
                }
                if(groupKeyAttr){ opts.groupKey = groupKeyAttr; }
                if(followupActionAttr){ opts.followupAction = followupActionAttr; }
                if(offsetAttr){
                    var parsedOffset = parseInt(offsetAttr, 10);
                    if(!isNaN(parsedOffset) && parsedOffset >= 0){
                        opts.offset = parsedOffset;
                    }
                }
            }

            var resolvedJobTitleFinal = resolvedJobTitle || '';
            if(!resolvedJobTitleFinal || !String(resolvedJobTitleFinal).trim()){
                addSystemMessage('برای استفاده از این گزینه، اول یک کارت شغلی را باز کنید.');
                return;
            }

            if(typeof window.dispatchUserMessage === 'function'){
                window.dispatchUserMessage(followupText, opts);
            }

            if(typeof window.removeFollowups === 'function'){
                window.removeFollowups();
            }
        }, true);

        function appendResponseMeta(text){
            if(!text) return;
            var $meta = $('<div class="bkja-response-meta"></div>').html(formatMessage(text));
            $messages.append($meta);
            $messages.scrollTop($messages.prop('scrollHeight'));
        }

        function attachFeedbackControls($bubble, meta, userMessage, responseText, options){
            if(!feedbackEnabled){
                return;
            }
            if(!$bubble || !$bubble.length || !config.ajax_url){
                return;
            }
            if($bubble.data('bkja-feedback')){
                return;
            }

            meta = meta || {};
            var normalizedMessage = meta.normalized_message || userMessage || '';
            if(!normalizedMessage){
                return;
            }

            options = options || {};
            var highlight = !!options.highlight;
            var autoOpen = !!options.autoOpen;

            var $wrap = $('<div class="bkja-feedback-wrap"></div>');
            var $cta = $('<button type="button" class="bkja-feedback-cta" aria-expanded="false"></button>');
            $cta.text(highlight ? 'نظرت درباره این پاسخ چیه؟' : 'ثبت بازخورد پاسخ');
            if(highlight){
                $cta.addClass('bkja-feedback-cta-highlight');
            }

            var $controls = $('<div class="bkja-feedback-controls" role="group" aria-label="بازخورد پاسخ" style="display:none;"></div>');
            var $like = $('<button type="button" class="bkja-feedback-btn like" aria-label="پاسخ مفید بود">👍</button>');
            var $dislike = $('<button type="button" class="bkja-feedback-btn dislike" aria-label="پاسخ نیاز به بهبود دارد">👎</button>');
            var $improve = $('<button type="button" class="bkja-feedback-toggle" aria-expanded="false">بهبود این پاسخ</button>');
            var $status = $('<span class="bkja-feedback-status" aria-live="polite"></span>');
            var $extra = $('<div class="bkja-feedback-extra" style="display:none;"></div>');
            var $tags = $('<input type="text" class="bkja-feedback-tags" placeholder="برچسب‌های اختیاری (مثل need_numbers)">');
            var $comment = $('<textarea class="bkja-feedback-comment" placeholder="توضیح اختیاری برای بهبود پاسخ"></textarea>');
            $extra.append($tags).append($comment);

            $controls.append($like).append($dislike).append($improve).append($status);
            $wrap.append($cta).append($controls).append($extra);
            $bubble.after($wrap);
            $bubble.data('bkja-feedback', true);

            var sending = false;
            var controlsVisible = false;

            function openControls(){
                if(controlsVisible){
                    return;
                }
                controlsVisible = true;
                $controls.slideDown(150);
                $cta.attr('aria-expanded','true').addClass('bkja-feedback-cta-open');
                $cta.removeClass('bkja-feedback-cta-highlight');
            }

            $cta.on('click', function(){
                if(sending){ return; }
                if(controlsVisible){
                    return;
                }
                openControls();
            });

            function sendFeedback(vote){
                if(sending){ return; }
                sending = true;
                $status.text('در حال ارسال بازخورد...');

                var bubbleEl = $bubble && $bubble.length ? $bubble.get(0) : null;
                var dataset = bubbleEl && bubbleEl.dataset ? bubbleEl.dataset : {};
                var datasetCategory = dataset && dataset.category ? dataset.category : '';
                var datasetJobTitle = dataset && dataset.jobTitle ? dataset.jobTitle : '';
                var datasetJobSlug = dataset && dataset.jobSlug ? dataset.jobSlug : '';
                if(!datasetCategory && bubbleEl){
                    datasetCategory = bubbleEl.getAttribute('data-category') || '';
                }
                if(!datasetJobTitle && bubbleEl){
                    datasetJobTitle = bubbleEl.getAttribute('data-job-title') || '';
                }
                if(!datasetJobSlug && bubbleEl){
                    datasetJobSlug = bubbleEl.getAttribute('data-job-slug') || '';
                }

                ensureSession();
                var payload = {
                    action: 'bkja_feedback',
                    nonce: config.nonce,
                    session: sessionId,
                    vote: vote,
                    message: normalizedMessage,
                    response: responseText || '',
                    category: datasetCategory || meta.category || '',
                    model: meta.model || '',
                    job_title: datasetJobTitle || meta.job_title || ''
                };
                if(datasetJobSlug || meta.job_slug){
                    payload.job_slug = datasetJobSlug || meta.job_slug || '';
                }
                if(vote === -1){
                    payload.tags = $.trim($tags.val());
                    payload.comment = $.trim($comment.val());
                }
                var requestBody = new URLSearchParams(payload).toString();
                fetch(config.ajax_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-BKJA-Session': sessionId
                    },
                    body: requestBody
                }).then(function(response){
                    if(!response.ok){
                        throw new Error('Request failed');
                    }
                    return response.json();
                }).then(function(res){
                    sending = false;
                    if(res && res.success){
                        $status.text('بازخورد شما ثبت شد. ممنونیم!');
                        $like.prop('disabled', true);
                        $dislike.prop('disabled', true);
                        $improve.prop('disabled', true);
                        $tags.prop('disabled', true);
                        $comment.prop('disabled', true);
                        $cta.prop('disabled', true).addClass('bkja-feedback-cta-disabled').text('بازخورد ثبت شد ✅');
                    } else {
                        $status.text('خطا در ثبت بازخورد. دوباره تلاش کنید.');
                    }
                }).catch(function(){
                    sending = false;
                    $status.text('خطا در ارتباط با سرور.');
                });
            }

            $like.on('click', function(){
                openControls();
                sendFeedback(1);
            });

            $dislike.on('click', function(){
                openControls();
                if($extra.is(':hidden')){
                    $extra.slideDown(150);
                    $improve.attr('aria-expanded','true');
                }
                sendFeedback(-1);
            });

            $improve.on('click', function(){
                var isOpen = $extra.is(':visible');
                $extra.slideToggle(150);
                $(this).attr('aria-expanded', (!isOpen).toString());
            });

            if(autoOpen){
                setTimeout(function(){ openControls(); }, 80);
            }
        }

        if($form.length){
            // ensure quick list placeholder
            if($('.bkja-quick-dropdown').length === 0){
                var $dropdownBtn = $('<button type="button" class="bkja-quick-dropdown-btn">سوالات آماده <span class="bkja-quick-arrow">▼</span></button>');
                var $dropdownWrap = $('<div style="position:relative;width:100%"></div>');
                var $dropdown = $('<div class="bkja-quick-dropdown" style="display:none;"></div>');
                $dropdownWrap.append($dropdownBtn).append($dropdown);
                $form.prepend($dropdownWrap);
            }
            const guideMessage = `
👋 سلام! من دستیار هوشمند شغلی هستم. می‌تونم کمکت کنم شغل مناسب شرایطت رو پیدا کنی، درآمد تقریبی هر شغل رو بدونی، یا بفهمی با سرمایه‌ای که داری چه کسب‌وکاری میشه راه انداخت.
امکانات من شامل این بخش‌هاست:
- 📂 دسته‌بندی مشاغل بر اساس نوع و صنعت
- 🔍 فیلتر مشاغل (سرمایه، درآمد، سختی، علاقه‌مندی)
- 📝 ثبت شغل توسط کاربران (برای معرفی تجربه‌های کاری یا فرصت‌های محلی)
- 👤 ثبت مشخصات پروفایل کاربر (سن، علایق، مهارت‌ها) برای پیشنهاد دقیق‌تر
- 🤖 پاسخ‌دهی هوشمند با توجه به تاریخچه گفتگو و ویژگی‌های شخصی شما
برای دسترسی به همه‌ی این قابلیت‌ها می‌تونید از منوی 📂 بالا سمت چپ استفاده کنید و بخش‌های مختلف رو ببینید. فقط کافیه از من سوال بپرسی یا از دکمه‌های آماده استفاده کنی. 😊
`;
            function getLowInvestmentJobs() {
              return "چه شغل‌هایی رو میشه با سرمایه کم (مثلاً زیر ۵۰ میلیون تومان) شروع کرد که سود مناسبی داشته باشه و ریسک پایینی داشته باشه؟";
            }
            function getJobIncomeList() {
              return "می‌تونی لیستی از مشاغل پرطرفدار در ایران رو بهم بدی و حدود درآمد هرکدوم رو هم توضیح بدی؟";
            }
            function compareJobs() {
              return "دو یا چند شغل مثل پزشکی، مهندسی یا برنامه‌نویسی رو از نظر درآمد، آینده شغلی، سختی کار و سرمایه اولیه مقایسه کن.";
            }
            function suggestSmallBusinesses() {
              return "چه کسب‌وکارهای کوچک و کم‌هزینه‌ای میشه در ایران شروع کرد که آینده خوبی داشته باشه و نیاز به سرمایه زیاد نداشته باشه؟";
            }
            function suggestJobsByPersonality() {
              return "با توجه به ویژگی‌های شخصیتی، علاقه‌مندی‌ها و شرایط سنی من، چه شغل‌هایی می‌تونن مناسب من باشن؟";
            }
                        const predefinedQuestions = [
                            { label: "راهنمای دستیار", fullText: guideMessage, icon: "📘" },
                            { label: "شغل با سرمایه کم", fullText: getLowInvestmentJobs(), icon: "💸" },
                            { label: "درآمد شغل‌ها", fullText: getJobIncomeList(), icon: "💰" },
                            { label: "مقایسه شغل‌ها", fullText: compareJobs(), icon: "⚖️" },
                            { label: "کسب‌وکار کوچک", fullText: suggestSmallBusinesses(), icon: "🏪" },
                            { label: "شغل مناسب شخصیت من", fullText: suggestJobsByPersonality(), icon: "🧑‍💼" }
                        ];
                                    var $dropdown = $('.bkja-quick-dropdown').empty();
                                    var grid = $('<div class="bkja-quick-grid"></div>');
                                    predefinedQuestions.forEach(function(q, idx){
                                        var $it = $('<div class="bkja-quick-item"></div>');
                                        var $icon = $('<span class="bkja-quick-icon"></span>').text(q.icon);
                                        var $label = $('<span class="bkja-quick-label"></span>').text(q.label);
                                        $it.append($icon).append($label);
                                        $it.on('click', function(){
                                            $dropdown.slideUp(200);
                                            $('.bkja-quick-dropdown-btn').removeClass('open');
                                            if(idx === 0){
                                                // فقط نمایش راهنما، ارسال به API نشود
                                                removeFollowups();
                                                pushBotHtml(formatMessage(q.fullText));
                                            } else {
                                                $input.val(q.fullText);
                                                $form.trigger('submit');
                                            }
                                        });
                                        grid.append($it);
                                    });
                                    $dropdown.append(grid);
                                    $('.bkja-quick-dropdown-btn').off('click').on('click', function(){
                                        $dropdown.slideToggle(200);
                                        $(this).toggleClass('open');
                                    });

            function normalizeForMatch(text){
                if(text === null || text === undefined){
                    return '';
                }
                return String(text).replace(/[\s‌]+/g,' ').trim().toLowerCase();
            }

            function isLikelyFollowupMessage(message){
                var normalized = normalizeForMatch(message);
                if(!normalized){
                    return false;
                }
                var followupKeywords = [
                    'چقدر','چقدره','درآمد','درامد','حقوق','حقوقش','درآمدش','چقدر درمیاره','چنده','دستمزد',
                    'سرمایه','هزینه','هزینه شروع','بودجه','سرمایه میخواد','سرمایه می‌خواد',
                    'مزایا','معایب','چالش','بازار','بازار کار','ریسک','سود','چطوره','چطور','چیه',
                    'سخته','امکان','شرایط','مهارت','مهارت‌ها','قدم بعدی','از کجا شروع کنم',
                    'شغل‌های جایگزین','شغلهای جایگزین','میانگین','بازه','مقایسه','مشابه'
                ];
                if(normalized.length <= 60){
                    for(var i=0;i<followupKeywords.length;i++){
                        if(normalized.indexOf(followupKeywords[i]) !== -1){
                            return true;
                        }
                    }
                }
                if(normalized.indexOf('این') !== -1 || normalized.indexOf('همین') !== -1 || normalized.indexOf('اون') !== -1){
                    return true;
                }
                return false;
            }

            function hasExplicitJobHint(message){
                var normalized = normalizeForMatch(message);
                if(!normalized){
                    return false;
                }
                var explicitKeywords = ['شغل','کار','حوزه','رشته','حرفه','درباره','در مورد','راجع','راجب','خصوص','زمینه'];
                for(var i=0;i<explicitKeywords.length;i++){
                    if(normalized.indexOf(explicitKeywords[i]) !== -1){
                        return true;
                    }
                }
                var tokens = normalized.split(' ');
                var stopwords = ['چقدر','چقدره','درآمد','درامد','حقوق','سرمایه','مزایا','معایب','بازار','ریسک','سود','این','همین','اون','چطور','چیه','چنده'];
                for(var t=0;t<tokens.length;t++){
                    var token = tokens[t];
                    if(!token || token.length < 3){
                        continue;
                    }
                    if(stopwords.indexOf(token) !== -1){
                        continue;
                    }
                    if(/(ی|گر|گری|کاری|چی|مند|کار)$/.test(token)){
                        return true;
                    }
                }
                return false;
            }

            function shouldStartPersonalityFlow(message){
                if(personalityFlow.active || personalityFlow.awaitingResult){
                    return false;
                }
                var normalized = normalizeForMatch(message);
                if(!normalized){
                    return false;
                }
                if(normalized.indexOf('شخصیت') === -1 && normalized.indexOf('تیپ') === -1 && normalized.indexOf('روحیه') === -1){
                    return false;
                }
                var verbs = ['میخوره','می‌خوره','هماهنگ','سازگار','مناسب','میاد','ارزیابی'];
                var verbFound = false;
                for(var i=0;i<verbs.length;i++){
                    if(normalized.indexOf(verbs[i]) !== -1){
                        verbFound = true;
                        break;
                    }
                }
                if(!verbFound){
                    return false;
                }
                if(normalized.indexOf('شغل') === -1 && normalized.indexOf('کار') === -1 && !lastKnownJobTitle){
                    return false;
                }
                return true;
            }

            function extractJobTitleFromMessage(message){
                if(!message){
                    return lastKnownJobTitle || '';
                }
                var job = '';
                var match = message.match(/شغل\s*(?:«|"|\')?\s*([^»"'؟\?\n]+?)(?:»|"|\'|\s|\?|؟|$)/);
                if(match && match[1]){
                    job = $.trim(match[1]);
                }
                if(!job){
                    match = message.match(/(?:درباره|در مورد|راجع|راجب|خصوص|حوزه)\s+([^\?\!\n]+?)(?:\s*(?:چی|چیه|است|می|؟|\?|$))/);
                    if(match && match[1]){
                        job = $.trim(match[1]);
                    }
                }
                if(!job){
                    job = lastKnownJobTitle || '';
                }
                return job;
            }

            function askNextPersonalityQuestion(){
                if(!personalityFlow.active){
                    return;
                }
                if(personalityFlow.step >= personalityFlow.questions.length){
                    completePersonalityFlow();
                    return;
                }
                var q = personalityFlow.questions[personalityFlow.step];
                pushBot('سوال ' + (personalityFlow.step + 1) + ') ' + q.text);
            }

            function startPersonalityFlow(initialMessage){
                personalityFlow.active = true;
                personalityFlow.awaitingResult = false;
                personalityFlow.answers = [];
                personalityFlow.step = 0;
                personalityFlow.jobTitle = extractJobTitleFromMessage(initialMessage) || 'این شغل';
                if(personalityFlow.jobTitle && personalityFlow.jobTitle !== 'این شغل'){
                    lastKnownJobTitle = personalityFlow.jobTitle;
                }
                var jobFragment = personalityFlow.jobTitle === 'این شغل' ? personalityFlow.jobTitle : 'شغل «' + personalityFlow.jobTitle + '»';
                pushBot('برای اینکه بفهمیم ' + jobFragment + ' با ویژگی‌هات هماهنگ است چند سوال کوتاه ازت می‌پرسم. لطفاً کوتاه و صادقانه جواب بده.', {
                    onComplete: function(){
                        setTimeout(function(){ askNextPersonalityQuestion(); }, 260);
                    }
                });
            }

            function handlePersonalityAnswer(answer){
                if(!personalityFlow.active){
                    return;
                }
                var q = personalityFlow.questions[personalityFlow.step];
                personalityFlow.answers.push({ question: q.text, answer: answer });
                personalityFlow.step += 1;
                if(personalityFlow.step < personalityFlow.questions.length){
                    setTimeout(function(){ askNextPersonalityQuestion(); }, 260);
                } else {
                    completePersonalityFlow();
                }
            }

            function completePersonalityFlow(){
                if(personalityFlow.awaitingResult){
                    return;
                }
                personalityFlow.active = false;
                personalityFlow.awaitingResult = true;
                var job = personalityFlow.jobTitle || lastKnownJobTitle || 'این شغل';
                var jobFragment = job === 'این شغل' ? job : 'شغل «' + job + '»';
                var summary = personalityFlow.answers.map(function(item, idx){
                    return (idx + 1) + '. ' + item.question + ' => ' + item.answer;
                }).join('\n');
                var prompt = 'می‌خوام بررسی کنی آیا ' + jobFragment + ' با شخصیت و ترجیحات من تناسب دارد یا نه.' +
                    '\nپاسخ‌های من به سوالات شخصیت‌شناسی:' + '\n' + summary + '\n' +
                    'لطفاً نتیجه را در سه بخش «تناسب کلی»، «دلایل سازگاری یا عدم سازگاری»، و «پیشنهاد قدم بعدی» ارائه بده و اگر این شغل مناسب نیست چند گزینه جایگزین مرتبط معرفی کن.';
                pushBot('سپاس از پاسخ‌هات! دارم بررسی می‌کنم که این شغل با روحیه‌ات هماهنگ هست یا نه...', {
                    onComplete: function(){
                        removeFollowups();
                        if(!isTruthy(config.is_logged_in) && !canGuestSendMessage()){
                            personalityFlow.awaitingResult = false;
                            handleGuestLimitExceeded({});
                            return;
                        }
                        sendMessageToServer(prompt, { contextMessage: prompt, highlightFeedback: true, _bypassLimit: true });
                        personalityFlow.answers = [];
                    }
                });
            }

            function dispatchUserMessage(message, options){
                options = options || {};
                var text = message;
                if(text === null || text === undefined){
                    text = '';
                }
                text = $.trim(String(text));
                if(!text){
                    return;
                }

                if(!isTruthy(config.is_logged_in)){
                    var limit = getGuestLimit();
                    if(limit <= 0){
                        handleGuestLimitExceeded({});
                        return;
                    }
                    if(getGuestUsageCount() >= limit){
                        handleGuestLimitExceeded({});
                        return;
                    }
                }

                removeFollowups();
                if(personalityFlow.awaitingResult){
                    personalityFlow.awaitingResult = false;
                }

                pushUser(text);
                $input.val('');

                if(personalityFlow.active){
                    handlePersonalityAnswer(text);
                    return;
                }

                if(shouldStartPersonalityFlow(text)){
                    startPersonalityFlow(text);
                    return;
                }

                var sendOptions = { contextMessage: text };
                var explicitJobHintInMessage = hasExplicitJobHint(text);
                var followupMessage = isLikelyFollowupMessage(text);
                if(typeof options.category === 'string' && options.category.length){
                    sendOptions.category = options.category;
                }
                if(typeof options.followupAction === 'string' && options.followupAction.length){
                    sendOptions.followupAction = options.followupAction;
                }
                if(!sendOptions.category && lastReplyMeta && typeof lastReplyMeta.category === 'string' && lastReplyMeta.category.length){
                    sendOptions.category = lastReplyMeta.category;
                }
                if(options.highlightFeedback){
                    sendOptions.highlightFeedback = true;
                }

                var explicitJobTitle = cleanJobHint(options.jobTitle);
                if(explicitJobTitle){
                    sendOptions.jobTitle = explicitJobTitle;
                }

                var explicitJobSlug = cleanJobHint(options.jobSlug);
                if(explicitJobSlug){
                    sendOptions.jobSlug = explicitJobSlug;
                }

                if(options.jobTitleId){
                    sendOptions.jobTitleId = options.jobTitleId;
                }

                if(options.groupKey){
                    sendOptions.groupKey = options.groupKey;
                }

                var normalizedSameJob = $.trim(String(text || '')).replace(/\s+/g,'').toLowerCase();
                var wantsSameJob = normalizedSameJob.indexOf('همینشغل') !== -1 || normalizedSameJob.indexOf('همینکار') !== -1 || normalizedSameJob.indexOf('همونشغل') !== -1;
                if(!sendOptions.jobTitle && !sendOptions.jobSlug && !sendOptions.jobTitleId && !sendOptions.groupKey && wantsSameJob && !explicitJobHintInMessage){
                    if(cleanJobHint(lastKnownJobTitle)){
                        sendOptions.jobTitle = cleanJobHint(lastKnownJobTitle);
                    }
                    if(cleanJobHint(lastKnownJobSlug)){
                        sendOptions.jobSlug = cleanJobHint(lastKnownJobSlug);
                    }
                    if(lastKnownJobTitleId){
                        sendOptions.jobTitleId = lastKnownJobTitleId;
                    }
                    if(lastKnownGroupKey){
                        sendOptions.groupKey = lastKnownGroupKey;
                    }
                }

                if(!sendOptions.jobTitle && !sendOptions.jobSlug && !sendOptions.jobTitleId && !sendOptions.groupKey && followupMessage && !explicitJobHintInMessage){
                    if(cleanJobHint(lastKnownJobTitle)){
                        sendOptions.jobTitle = cleanJobHint(lastKnownJobTitle);
                    }
                    if(cleanJobHint(lastKnownJobSlug)){
                        sendOptions.jobSlug = cleanJobHint(lastKnownJobSlug);
                    }
                    if(lastKnownJobTitleId){
                        sendOptions.jobTitleId = lastKnownJobTitleId;
                    }
                    if(lastKnownGroupKey){
                        sendOptions.groupKey = lastKnownGroupKey;
                    }
                }

                sendOptions._bypassLimit = true;
                sendMessageToServer(text, sendOptions);
            }
            window.dispatchUserMessage = dispatchUserMessage;

            function sendMessageToServer(message, opts, isRetry){
                opts = opts || {};
                var hasRetried = !!isRetry;
                var skipLimitCheck = !!opts._bypassLimit;
                if(!skipLimitCheck && !isTruthy(config.is_logged_in)){
                    var immediateLimit = getGuestLimit();
                    if(immediateLimit <= 0){
                        handleGuestLimitExceeded({});
                        return;
                    }
                    if(getGuestUsageCount() >= immediateLimit){
                        handleGuestLimitExceeded({});
                        return;
                    }
                }
                ensureSession();
                var contextMessage = opts.contextMessage || message;
                var payload = new URLSearchParams();
                payload.append('action', 'bkja_send_message');
                payload.append('nonce', config.nonce);
                payload.append('message', message);
                payload.append('session', sessionId);
                payload.append('category', opts.category || '');
                var jobTitleParam = cleanJobHint(opts.jobTitle);
                var jobSlugParam = cleanJobHint(opts.jobSlug);
                var jobTitleIdParam = opts.jobTitleId ? parseInt(opts.jobTitleId, 10) : '';
                var groupKeyParam = opts.groupKey ? String(opts.groupKey) : '';
                var followupActionParam = opts.followupAction ? String(opts.followupAction) : '';
                var offsetParam = (typeof opts.offset !== 'undefined' && opts.offset !== null) ? parseInt(opts.offset, 10) : '';
                payload.append('job_title', jobTitleParam || '');
                payload.append('job_slug', jobSlugParam || '');
                payload.append('job_title_id', jobTitleIdParam || '');
                payload.append('group_key', groupKeyParam || '');
                payload.append('followup_action', followupActionParam || '');
                payload.append('offset', offsetParam || '');

                fetch(config.ajax_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-BKJA-Session': sessionId
                    },
                    body: payload.toString()
                }).then(function(response){
                    return response.text().then(function(text){
                        var data = {};
                        if(text){
                            try {
                                data = JSON.parse(text);
                            } catch(parseError){
                                data = {};
                            }
                        }
                        if(!response.ok){
                            var error = new Error('Request failed');
                            if(data && data.data){
                                error.data = data.data;
                            } else {
                                error.data = data;
                            }
                            error.status = response.status;
                            throw error;
                        }
                        return data;
                    });
                }).then(function(res){
                    personalityFlow.awaitingResult = false;
                    if(res && res.data && res.data.error === 'invalid_nonce'){
                        if(!hasRetried){
                            refreshNonce().then(function(success){
                                if(success){
                                    sendMessageToServer(message, opts, true);
                                } else {
                                    pushBot('خطا در ارتباط با سرور');
                                }
                            });
                        } else {
                            pushBot('خطا در ارتباط با سرور');
                        }
                        return;
                    }
                    if(res && res.data && res.data.error === 'guest_limit'){
                        handleGuestLimitExceeded(res.data);
                        return;
                    }
                    if(res && res.success){
                        if(res.data){
                            syncSessionFromPayload(res.data);
                        }
                        if(!isTruthy(config.is_logged_in)){
                            if(res.data){
                                if(Object.prototype.hasOwnProperty.call(res.data, 'guest_message_limit')){
                                    updateGuestLimitFromServer(res.data.guest_message_limit);
                                }
                                if(Object.prototype.hasOwnProperty.call(res.data, 'guest_message_count')){
                                    setGuestUsageCount(res.data.guest_message_count);
                                } else {
                                    incrementGuestUsage();
                                }
                            } else {
                                incrementGuestUsage();
                            }
                        }
                        var reply = res.data.reply || '';
                        var buttons = Array.isArray(res.data.buttons) ? res.data.buttons : [];
                        var fromCache = !!res.data.from_cache;
                        var meta = res.data.meta || {};
                        var cards = Array.isArray(res.data.cards) ? res.data.cards : [];
                        var primaryMeta = (cards[0] && cards[0].meta) ? cards[0].meta : meta;
                        if(primaryMeta && typeof primaryMeta.job_title === 'string' && primaryMeta.job_title.trim()){
                            lastKnownJobTitle = primaryMeta.job_title.trim();
                        }
                        lastReplyMeta = primaryMeta;
                        window.lastReplyMeta = lastReplyMeta;

                        var renderCard = function(card){
                            var cardMeta = card && card.meta ? card.meta : meta;
                            var cardText = card && typeof card.text === "string" ? card.text : reply;
                            var cardFollowups = Array.isArray(card && card.buttons) ? card.buttons : buttons;

                            if(cardMeta && typeof cardMeta === "object"){
                                lastReplyMeta = cardMeta;
                                window.lastReplyMeta = lastReplyMeta;
                            }

                            pushBot(cardText, {
                                onComplete: function($bubble){
                                    applyAssistantMeta($bubble, cardMeta);
                                    if (SHOW_TECH_META) {
                                        if(fromCache){
                                            appendResponseMeta("🔄 این پاسخ از حافظه کش ارائه شد تا سریع‌تر به شما نمایش داده شود.");
                                        }
                                        if(cardMeta.source === "database"){
                                            appendResponseMeta("📚 این پاسخ مستقیماً از داده‌های داخلی شغل تهیه شد.");
                                        } else if(cardMeta.source === "job_context"){
                                            appendResponseMeta("ℹ️ به دلیل محدودیت ارتباط با API، پاسخ بر اساس داده‌های داخلی آماده شد.");
                                        } else if(cardMeta.context_used && cardMeta.source === "openai"){
                                            appendResponseMeta("📊 برای این پاسخ از داده‌های داخلی ثبت‌شده استفاده شد.");
                                        }
                                    }

                                    var finalSuggestions = renderFollowupButtons(cardFollowups, cardMeta);
                                    var highlightFeedback = !!opts.highlightFeedback || finalSuggestions.length === 0;
                                    if(feedbackEnabled && cardText && cardText.length){
                                        attachFeedbackControls($bubble, cardMeta, contextMessage, cardText, { highlight: highlightFeedback });
                                    }
                                    maybeAnnounceGuestLimitReached();
                                }
                            });
                        };

                        if(cards.length){
                            cards.forEach(function(card){
                                renderCard(card);
                            });
                        } else {
                            renderCard({ text: reply, meta: meta, buttons: buttons });
                        }
                    } else {
                        pushBot('خطا در پاسخ');
                    }
                }).catch(function(err){
                    personalityFlow.awaitingResult = false;
                    var data = err && err.data ? err.data : null;
                    if(!data && err && err.responseJSON){
                        data = err.responseJSON;
                    }
                    if(data && data.data){
                        data = data.data;
                    }
                    if(data && data.error === 'invalid_nonce'){
                        if(!hasRetried){
                            refreshNonce().then(function(success){
                                if(success){
                                    sendMessageToServer(message, opts, true);
                                } else {
                                    pushBot('خطا در ارتباط با سرور');
                                }
                            });
                        } else {
                            pushBot('خطا در ارتباط با سرور');
                        }
                        return;
                    }
                    if(data){
                        syncSessionFromPayload(data);
                    }
                    if(data && data.error === 'guest_limit'){
                        handleGuestLimitExceeded(data);
                    } else {
                        pushBot('خطا در ارتباط با سرور');
                    }
                });
            }

            $form.on('submit', function(e){
                e.preventDefault();
                var msg = $input.val();
                dispatchUserMessage(msg);
            });
        }

        // === تاریخچه گفتگو ===
        $(document).on("click", "#bkja-open-history", function(){
            var $btn = $(this);
            var $container = $btn.closest("#bkja-history-container");
            var $panel = $container.find("#bkja-history-panel");

            if($btn.hasClass("is-loading")){
                return;
            }

            if($panel.is(":visible")){
                $panel.stop(true, true).slideUp(200);
                return;
            }

            $btn.addClass("is-loading");
            $panel.stop(true, true).hide().html('<div class="bkja-history-title">در حال بارگذاری...</div>');

            // بستن زیرمنوهای دسته‌ها هنگام نمایش تاریخچه
            $(".bkja-jobs-sublist").remove();
            $(".bkja-category-item.open").removeClass("open");

            var revealPanel = function(){
                $panel.stop(true, true).slideDown(220);
                $btn.removeClass("is-loading");
            };

            ajaxWithNonce({
                action: "bkja_get_history",
                session: sessionId
            }).done(function(res){
                var limitPayload = extractGuestLimitPayload(res);
                if(limitPayload){
                    handleGuestLimitExceeded(limitPayload);
                    var limitNotice = '';
                    if(limitPayload && limitPayload.message){
                        limitNotice = $.trim(String(limitPayload.message));
                    }
                    if(!limitNotice){
                        limitNotice = 'برای مشاهده تاریخچه لطفاً وارد حساب کاربری شوید.';
                    }
                    $panel.html('<div class="bkja-history-title" style="color:#d32f2f;">'+esc(limitNotice)+'</div>');
                    revealPanel();
                    return;
                }
                if(res && res.success){
                    var html = '<div class="bkja-history-title">گفتگوهای شما</div>';
                    html += '<div class="bkja-history-list">';
                    if(res.data.items && res.data.items.length){
                        res.data.items.forEach(function(it){
                            if(it.message){
                                html += '<div class="bkja-history-item user">'+esc(it.message)+'</div>';
                            }
                            if(it.response){
                                html += '<div class="bkja-history-item bot">'+esc(it.response)+'</div>';
                            }
                        });
                    } else {
                        html += '<div>📭 تاریخچه‌ای یافت نشد.</div>';
                    }
                    html += '</div>';
                    $panel.html(html);
                    revealPanel();
                } else {
                    $panel.html('<div class="bkja-history-title" style="color:#d32f2f;">خطا در دریافت تاریخچه</div>');
                    revealPanel();
                }
            }).fail(function(){
                $panel.html('<div class="bkja-history-title" style="color:#d32f2f;">خطا در دریافت تاریخچه</div>');
                revealPanel();
            });
        });

        // === منوی دسته‌ها و شغل‌ها ===
        function loadCategories(){
            ajaxWithNonce({
                action: "bkja_get_categories"
            }).done(function(res){
                var limitPayload = extractGuestLimitPayload(res);
                if(limitPayload){
                    handleGuestLimitExceeded(limitPayload);
                    var message = limitPayload && limitPayload.message ? $.trim(String(limitPayload.message)) : '';
                    if(!message){
                        message = 'برای مشاهده دسته‌بندی‌ها لطفاً وارد شوید.';
                    }
                    $("#bkja-categories-list").html('<li style="padding:8px 0;color:#d32f2f;">'+esc(message)+'</li>');
                    return;
                }
                if(res && res.success && res.data.categories){
                    var $list = $("#bkja-categories-list").empty();
                    // حذف بخش تاریخچه قبلی و افزودن نسخه ثابت داخل سایدبار
                    $("#bkja-history-container").remove();
                    var $historyContainer = $('<div id="bkja-history-container" class="bkja-history-container"></div>');
                    var $historyBtn = $('<button id="bkja-open-history" type="button" class="bkja-history-toggle">🕘 گفتگوهای شما</button>');
                    var $historyPanel = $('<div id="bkja-history-panel" class="bkja-history-panel"></div>');
                    $historyContainer.append($historyBtn).append($historyPanel);
                    $(".bkja-profile-section").after($historyContainer);
                    res.data.categories.forEach(function(cat){
                        var icon = cat.icon || "💼";
                        if(cat.name){
                            if(typeof cat.slug !== 'undefined' && cat.slug !== null){
                                var slugKey = String(cat.slug);
                                categoryDisplayNames[slugKey] = cat.name;
                                categoryDisplayNames[slugKey.toLowerCase()] = cat.name;
                            }
                            if(typeof cat.id !== 'undefined' && cat.id !== null){
                                var idKey = String(cat.id);
                                categoryDisplayNames[idKey] = cat.name;
                            }
                        }
                        var $li = $('<li class="bkja-category-item" data-id="'+cat.id+'"><span class="bkja-cat-icon">'+icon+'</span> <span>'+esc(cat.name)+'</span></li>');
                        $list.append($li);
                    });
                }
            }).fail(function(){
                $("#bkja-categories-list").html('<li style="padding:8px 0;color:#d32f2f;">خطا در دریافت دسته‌بندی‌ها</li>');
            });
        }
        loadCategories();

        // کلیک روی دسته → گرفتن عناوین پایه شغل
        $(document).on("click",".bkja-category-item", function(e){
            e.stopPropagation();
            var $cat = $(this);
            var catId = $cat.data("id");

            if($cat.hasClass("open")){
                $cat.removeClass("open");
                $cat.next('.bkja-jobs-sublist').slideUp(200,function(){$(this).remove();});
                return;
            }

            $cat.siblings(".bkja-category-item.open").removeClass("open");
            $cat.siblings(".bkja-category-item").each(function(){
                $(this).next('.bkja-jobs-sublist').remove();
            });

            $cat.addClass("open");
            var $sublist = $('<div class="bkja-jobs-sublist">⏳ در حال بارگذاری...</div>');
            $cat.next('.bkja-jobs-sublist').remove();
            $cat.after($sublist);

            ajaxWithNonce({
                action:"bkja_get_jobs",
                category_id:catId
            }).done(function(res){
                var $sub = $cat.next('.bkja-jobs-sublist').empty();
                var limitPayload = extractGuestLimitPayload(res);
                if(limitPayload){
                    handleGuestLimitExceeded(limitPayload);
                    var limitNotice = limitPayload && limitPayload.message ? $.trim(String(limitPayload.message)) : '';
                    if(!limitNotice){
                        limitNotice = 'برای مشاهده فهرست مشاغل لطفاً وارد شوید.';
                    }
                    $sub.append('<div style="color:#d32f2f;">'+esc(limitNotice)+'</div>');
                    return;
                }
                if(res && res.success && res.data.jobs && res.data.jobs.length){
                    res.data.jobs.forEach(function(job){
                        var titleLabel = job.label || job.job_title || job.title || '';
                        var groupKey = job.group_key || '';
                        var jobTitleIds = Array.isArray(job.job_title_ids) ? job.job_title_ids.join(',') : '';
                        var $j = $('<div class="bkja-job-title-item" data-id="'+job.id+'" data-group-key="'+esc(groupKey)+'" data-label="'+esc(titleLabel)+'" data-job-title-ids="'+esc(jobTitleIds)+'" data-slug="'+esc(job.slug||'')+'">🧭 '+esc(titleLabel)+'</div>');
                        $sub.append($j);
                    });
                } else {
                    $sub.append('<div>❌ شغلی یافت نشد.</div>');
                }
            }).fail(function(){
                var $sub = $cat.next('.bkja-jobs-sublist').empty();
                $sub.append('<div style="color:#d32f2f;">خطا در دریافت شغل‌ها.</div>');
            });
        });

        // کلیک روی عنوان پایه → نمایش خلاصه و رکوردهای شغل (تجمیع‌شده)
        $(document).on("click", ".bkja-job-title-item", function(e){
            e.stopPropagation();
            var $titleItem = $(this);
            var jobTitleId = parseInt($titleItem.data('id'),10) || 0;
            var baseLabel = $.trim($titleItem.data('label') || $titleItem.text().replace('🧭',''));
            var groupKey = $titleItem.data('group-key') || '';
            var jobSlug = $titleItem.data('slug') || '';
            var jobTitleIdsStr = $titleItem.data('job-title-ids') || '';
            var jobTitleIds = [];
            if(jobTitleIdsStr){
                jobTitleIds = String(jobTitleIdsStr).split(',').map(function(id){ return parseInt(id,10)||0; }).filter(function(v){ return v>0; });
            }

            lastKnownJobTitle = baseLabel || lastKnownJobTitle;
            lastKnownJobTitleId = jobTitleId || lastKnownJobTitleId;
            lastKnownGroupKey = groupKey || lastKnownGroupKey;
            lastKnownJobSlug = jobSlug || lastKnownJobSlug;

            $messages.append('<div class="bkja-bubble user">ℹ️ درخواست اطلاعات شغل '+esc(baseLabel)+'</div>');
            $messages.scrollTop($messages.prop("scrollHeight"));

            showJobSummaryAndRecords({
                job_title: baseLabel,
                job_title_id: jobTitleId,
                group_key: groupKey,
                job_slug: jobSlug,
                label: baseLabel,
                job_title_ids: jobTitleIds,
                job_label: baseLabel
            }, baseLabel);

            $(".bkja-category-item.open").removeClass("open");
            $(".bkja-jobs-sublist").slideUp(200,function(){ $(this).remove(); });
            $("#bkja-menu-panel").removeClass("bkja-open");
            $("#bkja-menu-toggle").attr("aria-expanded","false");
        });

        // نمایش خلاصه و رکوردهای شغل با دکمه نمایش بیشتر
        function showJobSummaryAndRecords(job_title, display_title) {
            // دریافت خلاصه و اولین سری رکوردها با هم
            var payloadSummary = { action: "bkja_get_job_summary" };
            var payloadRecords = { action: "bkja_get_job_records", limit: 5, offset: 0 };
            var displayTitle = display_title;

            if(typeof job_title === 'object' && job_title !== null){
                if(job_title.job_title){ payloadSummary.job_title = job_title.job_title; payloadRecords.job_title = job_title.job_title; }
                if(job_title.job_title_id){ payloadSummary.job_title_id = job_title.job_title_id; payloadRecords.job_title_id = job_title.job_title_id; }
                if(job_title.group_key){ payloadSummary.group_key = job_title.group_key; payloadRecords.group_key = job_title.group_key; }
                if(job_title.job_title_ids){ payloadSummary.job_title_ids = job_title.job_title_ids; payloadRecords.job_title_ids = job_title.job_title_ids; }
                if(job_title.job_label){ payloadSummary.job_label = job_title.job_label; payloadRecords.job_label = job_title.job_label; }
                if(job_title.job_slug){ payloadSummary.job_slug = job_title.job_slug; }
                if(job_title.label && !displayTitle){ displayTitle = job_title.label; }
            } else {
                payloadSummary.job_title = job_title;
                payloadRecords.job_title = job_title;
            }

            if((window.BKJA_DEBUG || (typeof wp !== 'undefined' && wp && wp.debug) || (typeof BKJA !== 'undefined' && BKJA && BKJA.debug)) && typeof console !== 'undefined' && console.log){
                console.log('BKJA sidebar payload summary:', payloadSummary);
                console.log('BKJA sidebar payload records:', payloadRecords);
            }

            $.when(
                ajaxWithNonce(payloadSummary),
                ajaxWithNonce(payloadRecords)
            ).done(function(summaryRes, recordsRes) {
                var summaryPayload = summaryRes && summaryRes[0] ? extractGuestLimitPayload(summaryRes[0]) : null;
                var recordsPayload = recordsRes && recordsRes[0] ? extractGuestLimitPayload(recordsRes[0]) : null;
                var limitPayload = summaryPayload || recordsPayload;
                if(limitPayload){
                    handleGuestLimitExceeded(limitPayload);
                    var limitNotice = limitPayload && limitPayload.message ? $.trim(String(limitPayload.message)) : '';
                    if(!limitNotice){
                        limitNotice = 'برای مشاهده جزئیات شغل لطفاً وارد شوید.';
                    }
                    pushBotHtml('<div style="color:#d32f2f;">'+esc(limitNotice)+'</div>');
                    return;
                }
                var s = summaryRes[0] && summaryRes[0].success && summaryRes[0].data && summaryRes[0].data.summary ? summaryRes[0].data.summary : null;
                var records = recordsRes[0] && recordsRes[0].success && recordsRes[0].data && recordsRes[0].data.records ? recordsRes[0].data.records : [];
                var totalCount = s && typeof s.count_reports !== 'undefined' ? s.count_reports : records.length;
                var hasMore = recordsRes[0] && recordsRes[0].success && recordsRes[0].data && recordsRes[0].data.has_more ? true : false;
                var nextOffset = recordsRes[0] && recordsRes[0].data ? recordsRes[0].data.next_offset : null;
                var pageLimit = recordsRes[0] && recordsRes[0].data && recordsRes[0].data.limit ? parseInt(recordsRes[0].data.limit,10) : 5;
                function fmtMillion(val){
                    var num = parseFloat(val);
                    if(isNaN(num) || num <= 0){
                        return '';
                    }

                    var millionValue = num / 1000000;
                    if(millionValue <= 0){
                        return '';
                    }

                    var rounded;
                    if(millionValue < 20){
                        rounded = Math.round(millionValue * 10) / 10;
                    } else {
                        rounded = Math.round(millionValue);
                    }

                    var formatted = rounded.toString();
                    if(formatted.indexOf('.') !== -1){
                        formatted = formatted.replace(/\.0+$/, '');
                    }

                    var parts = formatted.split('.');
                    var intPart = parts[0] || '';
                    var decPart = parts.length > 1 ? parts[1] : '';
                    intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                    formatted = decPart ? (intPart + '.' + decPart) : intPart;

                    var digitsEn = ['0','1','2','3','4','5','6','7','8','9'];
                    var digitsFa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
                    for(var i=0; i<digitsEn.length; i++){
                        formatted = formatted.replace(new RegExp(digitsEn[i], 'g'), digitsFa[i]);
                    }
                    formatted = formatted.replace(/,/g, '٬');

                    return formatted + ' میلیون تومان';
                }

                function ensureMonthlyUnit(label){
                    if(!label){
                        return '';
                    }
                    if(label.indexOf('در ماه') !== -1){
                        return label;
                    }
                    if(label.indexOf('میلیون تومان') !== -1){
                        return label.replace('میلیون تومان', 'میلیون تومان در ماه');
                    }
                    return label + ' در ماه';
                }

                function stripMillionUnit(label){
                    if(!label){
                        return '';
                    }
                    return label.replace(/\s*میلیون تومان(?:\s*در ماه)?/g, '').trim();
                }

                var titleToShow = displayTitle || (s && (s.job_title_label || s.job_title)) || (typeof job_title === 'object' && job_title.label ? job_title.label : job_title);
                var summaryJobTitle = payloadRecords.job_title || payloadSummary.job_title || titleToShow;
                var summaryJobTitleId = payloadRecords.job_title_id || (s && s.job_title_id ? s.job_title_id : '');
                var summaryGroupKey = (s && s.group_key) ? s.group_key : (payloadRecords.group_key || '');
                var html = '<div class="bkja-job-summary-card">';
                html += '<div class="bkja-job-summary-header">';
                if (s) {
                    html += '<h4>💼 ' + esc(titleToShow) + '</h4>';
                    html += '<div class="bkja-job-summary-meta">';
                    var reportCount = s.count_reports ? parseInt(s.count_reports, 10) : 0;
                    html += '<span>🔢 تعداد تجربه‌های ثبت‌شده: ' + esc(reportCount || records.length) + '</span>';
                    html += '</div>';
                } else {
                    html += '<h4>💼 ' + esc(titleToShow) + '</h4>';
                    html += '<div>❌ خلاصه‌ای برای این شغل یافت نشد.</div>';
                }
                html += '</div>';
                if (s) {
                    var isTechnicalJob = false;
                    var normalizedTitle = (titleToShow || '').replace(/[\s‌]+/g, ' ').toLowerCase();
                    var techKeywords = ['برنامه', 'نرم افزار', 'نرم‌افزار', 'مهندس', 'توسعه', 'dev', 'developer', 'data', 'هوش مصنوعی'];
                    for(var tk=0; tk<techKeywords.length; tk++){
                        if(normalizedTitle.indexOf(techKeywords[tk]) !== -1){
                            isTechnicalJob = true;
                            break;
                        }
                    }
                    var windowMonths = s.window_months ? parseInt(s.window_months, 10) : 0;
                    var experienceCount = s.count_reports ? parseInt(s.count_reports, 10) : reportCount;
                    var lowExperienceData = experienceCount > 0 && experienceCount < 3;
                    var totalRecords = s.total_records ? parseInt(s.total_records, 10) : reportCount;
                    var incomeValidCount = s.income_valid_count ? parseInt(s.income_valid_count, 10) : 0;
                    var incomeDataLow = (totalRecords <= 2 || incomeValidCount <= 2);
                    var noteParts = [];
                    var noteText = 'ℹ️ این آمار از گزارش‌های کاربران این شغل جمع‌آوری شده است' + (windowMonths ? ' (حدود ' + windowMonths + ' ماه اخیر)' : '') + ' و منبع رسمی نیست.';
                    noteParts.push(esc(noteText));
                    var warningText = '';
                    if (lowExperienceData) {
                        warningText = '⚠️ داده‌ها برای این شغل محدود است (' + esc(experienceCount) + ' تجربه) و اعداد تقریبی هستند.';
                    } else if ((s.data_limited && s.count_reports) || incomeDataLow) {
                        warningText = '⚠️ داده‌های موجود محدود است و نتایج تقریبی گزارش می‌شود.';
                    }
                    if (warningText) {
                        noteParts.push(esc(warningText));
                    }
                    html += '<div class="bkja-job-summary-note">' + noteParts.join('<br>') + '</div>';

                    var singleIncome = incomeValidCount === 1;
                    var incomeUnitGuessed = !!s.income_unit_guessed;
                    var incomeCompositeCount = s.income_composite_count ? parseInt(s.income_composite_count, 10) : 0;

                    if(totalRecords > 0 && incomeValidCount <= 0){
                        html += '<p>💵 درآمد ماهانه: داده کافی برای عدد دقیق نداریم.</p>';
                    } else {
                        var incomeText = '';
                        var incomeLabelPrefix = (s.avg_income_method === 'median') ? 'میانه' : 'میانگین';
                        var labelPrefix = incomeDataLow ? 'برآورد تقریبی' : incomeLabelPrefix;
                        var avgIncomeLabel = s.avg_income_label ? s.avg_income_label : (s.avg_income ? fmtMillion(s.avg_income) : '');
                        if(avgIncomeLabel){
                            avgIncomeLabel = ensureMonthlyUnit(avgIncomeLabel);
                            incomeText += labelPrefix + ': ' + esc(avgIncomeLabel);
                        }
                        if(singleIncome && avgIncomeLabel){
                            incomeText += ' (تنها 1 گزارش معتبر)';
                        }
                        if(incomeUnitGuessed && avgIncomeLabel){
                            incomeText += ' (واحد از متن حدس زده شده)';
                        }
                        var minIncomeLabel = s.min_income_label ? s.min_income_label : (s.min_income ? fmtMillion(s.min_income) : '');
                        var maxIncomeLabel = s.max_income_label ? s.max_income_label : (s.max_income ? fmtMillion(s.max_income) : '');
                        if(minIncomeLabel && maxIncomeLabel){
                            var minValue = stripMillionUnit(minIncomeLabel);
                            var maxValue = stripMillionUnit(maxIncomeLabel);
                            incomeText += (incomeText ? ' | ' : '') + 'بازه: ' + esc(minValue) + ' تا ' + esc(maxValue) + ' میلیون تومان در ماه';
                        } else if (avgIncomeLabel) {
                            incomeText += (incomeText ? ' | ' : '') + 'بازه: نامشخص';
                        }
                        if(incomeText){
                            html += '<p>💵 درآمد ماهانه کاربران: ' + incomeText + '</p>';
                        }
                    }

                    if(incomeCompositeCount > 0){
                        html += '<div class="bkja-job-summary-note bkja-income-composite">';
                        html += '<div><strong>💵 درآمد ترکیبی (حقوق + فعالیت جانبی)</strong></div>';
                        html += '<div>برخی کاربران ترکیب حقوق ثابت و کار آزاد دارند؛ این اعداد در میانگین لحاظ نشده‌اند.</div>';
                        html += '<div>تعداد تجربه‌های درآمد ترکیبی: ' + esc(incomeCompositeCount) + '</div>';
                        html += '</div>';
                    }

                    var investText = '';
                    var avgInvestmentLabel = s.avg_investment_label ? s.avg_investment_label : (s.avg_investment ? fmtMillion(s.avg_investment) : '');
                    if(avgInvestmentLabel){
                        investText += 'میانگین: ' + esc(avgInvestmentLabel);
                        if(s.investment_unit_guessed){
                            investText += ' (واحد از متن حدس زده شده)';
                        }
                    }
                    var minInvestmentLabel = s.min_investment_label ? s.min_investment_label : (s.min_investment ? fmtMillion(s.min_investment) : '');
                    var maxInvestmentLabel = s.max_investment_label ? s.max_investment_label : (s.max_investment ? fmtMillion(s.max_investment) : '');
                    if(minInvestmentLabel && maxInvestmentLabel){
                        investText += (investText ? ' | ' : '') + 'بازه: ' + esc(minInvestmentLabel) + ' تا ' + esc(maxInvestmentLabel);
                    }
                    if(investText){
                        html += '<p>💰 سرمایه لازم: ' + investText + '</p>';
                    }

                    if (s.avg_experience_years) {
                        html += '<p>⏳ میانگین سابقه: حدود ' + esc(s.avg_experience_years) + ' سال</p>';
                    }
                    if (s.avg_hours_per_day) {
                        html += '<p>⏱ میانگین ساعت کار: حدود ' + esc(s.avg_hours_per_day) + ' ساعت در روز</p>';
                    }
                    if (s.avg_days_per_week) {
                        html += '<p>📅 میانگین روز کاری: حدود ' + esc(s.avg_days_per_week) + ' روز در هفته</p>';
                    }
                    if (s.dominant_employment_label) {
                        html += '<p>🧩 نوع اشتغال رایج: ' + esc(s.dominant_employment_label) + '</p>';
                    }
                    if (s.gender_summary) {
                        html += '<p>👤 ' + esc(s.gender_summary) + '</p>';
                    }

                    if (s.cities && s.cities.length){
                        html += '<p>📍 شهرهای پرتکرار: ' + esc(s.cities.join('، ')) + '</p>';
                    }
                    if (s.advantages && s.advantages.length){
                        html += '<p>⭐ مزایا: ' + esc(s.advantages.join('، ')) + '</p>';
                    }
                    if (s.disadvantages && s.disadvantages.length){
                        html += '<p>⚠️ معایب: ' + esc(s.disadvantages.join('، ')) + '</p>';
                    }

                }
                html += '</div>';
                pushBotHtml(html);
                // نمایش رکوردهای کاربران
                if(records && records.length){
                    records.forEach(function(r){
                        var recHtml = '<div class="bkja-job-record-card">';
                        var genderLabel = r.gender_label || r.gender;
                        var variantLabel = r.variant_title || r.job_title_label || summaryJobTitle;
                        if(variantLabel){
                            recHtml += '<h5>🧑‍🏫 ' + esc(variantLabel) + '</h5>';
                        } else {
                            recHtml += '<h5>🧑‍💼 تجربه کاربر</h5>';
                        }
                        if (r.created_at_display) recHtml += '<p>⏱️ ' + esc(r.created_at_display) + '</p>';
                        if (r.income) {
                            var incomeNote = r.income_note ? (' (' + esc(r.income_note) + ')') : '';
                            recHtml += '<p>💵 درآمد: ' + esc(r.income) + incomeNote + '</p>';
                        }
                        if (r.investment) recHtml += '<p>💰 سرمایه: ' + esc(r.investment) + '</p>';
                        if (r.city) recHtml += '<p>📍 شهر: ' + esc(r.city) + '</p>';
                        if (r.employment_label) recHtml += '<p>💼 نوع اشتغال: ' + esc(r.employment_label) + '</p>';
                        if (genderLabel) {
                            recHtml += '<p>👤 جنسیت: ' + esc(genderLabel) + '</p>';
                        }
                        if (r.advantages) recHtml += '<p>⭐ مزایا: ' + esc(r.advantages) + '</p>';
                        if (r.disadvantages) recHtml += '<p>⚠️ معایب: ' + esc(r.disadvantages) + '</p>';
                        if (r.details) recHtml += '<p>📝 توضیحات: ' + esc(r.details) + '</p>';
                        recHtml += '</div>';
                        pushBotHtml(recHtml);
                    });
                    // اگر رکورد بیشتری وجود دارد دکمه نمایش بیشتر اضافه شود
                    if(hasMore){
                        var nextOffsetVal = nextOffset !== null && typeof nextOffset !== 'undefined' ? nextOffset : pageLimit;
                        var moreBtn = '<button class="bkja-show-records-btn" data-title="'+esc(summaryJobTitle)+'" data-title-id="'+esc(summaryJobTitleId)+'" data-group-key="'+esc(summaryGroupKey)+'" data-offset="'+esc(nextOffsetVal)+'" data-limit="'+esc(pageLimit)+'">نمایش بیشتر تجربه کاربران</button>';
                        pushBotHtml(moreBtn);
                    }
                } else {
                    pushBotHtml('<div>📭 تجربه‌ای برای این شغل ثبت نشده است.</div>');
                }

                renderFollowupButtons([], {
                    job_title: summaryJobTitle,
                    job_title_label: summaryJobTitle,
                    job_report_count: typeof totalCount !== 'undefined' ? totalCount : null,
                    clarification_options: [],
                    query_intent: 'general_exploratory'
                });
            }).fail(function(){
                pushBotHtml('<div style="color:#d32f2f;">خطا در دریافت اطلاعات شغل.</div>');
            });
        }

        // هندل کلیک روی دکمه نمایش رکوردها
        $(document).on('click', '.bkja-show-records-btn', function() {
            var job_title = $(this).data('title');
            var job_title_id = $(this).data('title-id');
            var group_key = $(this).data('group-key');
            var offset = parseInt($(this).data('offset')) || 0;
            var limit = parseInt($(this).data('limit')) || 5;
            var $btn = $(this);
            var defaultLabel = 'نمایش بیشتر تجربه کاربران';
            $btn.prop('disabled', true).text('⏳ در حال دریافت...');
            ajaxWithNonce({
                action: "bkja_get_job_records",
                job_title: job_title,
                job_title_id: job_title_id,
                group_key: group_key,
                limit: limit,
                offset: offset
            }).done(function(res) {
                var limitPayload = extractGuestLimitPayload(res);
                if(limitPayload){
                    handleGuestLimitExceeded(limitPayload);
                    var limitNotice = limitPayload && limitPayload.message ? $.trim(String(limitPayload.message)) : '';
                    if(!limitNotice){
                        limitNotice = 'برای مشاهده تجربه کاربران لطفاً وارد شوید.';
                    }
                    $btn.prop('disabled', false).text(defaultLabel);
                    pushBotHtml('<div style="color:#d32f2f;">'+esc(limitNotice)+'</div>');
                    return;
                }
                $btn.prop('disabled', false).text(defaultLabel);
                if (res && res.success && res.data && res.data.records && res.data.records.length) {
                    var records = res.data.records;
                    var hasMore = res.data.has_more ? true : false;
                    var nextOffsetVal = typeof res.data.next_offset !== 'undefined' && res.data.next_offset !== null ? res.data.next_offset : offset + limit;

                    records.forEach(function(r) {
                        var html = '<div class="bkja-job-record-card">';
                        var genderLabel = r.gender_label || r.gender;
                        var variantLabel = r.variant_title || r.job_title_label || job_title;
                        if(variantLabel){
                            html += '<h5>🧑‍🏫 ' + esc(variantLabel) + '</h5>';
                        } else {
                            html += '<h5>🧑‍💼 تجربه کاربر</h5>';
                        }
                        if (r.created_at_display) html += '<p>⏱️ ' + esc(r.created_at_display) + '</p>';
                        if (r.income) {
                            var incomeNote = r.income_note ? (' (' + esc(r.income_note) + ')') : '';
                            html += '<p>💵 درآمد: ' + esc(r.income) + incomeNote + '</p>';
                        }
                        if (r.investment) html += '<p>💰 سرمایه: ' + esc(r.investment) + '</p>';
                        if (r.city) html += '<p>📍 شهر: ' + esc(r.city) + '</p>';
                        if (r.employment_label) html += '<p>💼 نوع اشتغال: ' + esc(r.employment_label) + '</p>';
                        if (genderLabel) {
                            html += '<p>👤 جنسیت: ' + esc(genderLabel) + '</p>';
                        }
                        if (r.advantages) html += '<p>⭐ مزایا: ' + esc(r.advantages) + '</p>';
                        if (r.disadvantages) html += '<p>⚠️ معایب: ' + esc(r.disadvantages) + '</p>';
                        if (r.details) html += '<p>📝 توضیحات: ' + esc(r.details) + '</p>';
                        html += '</div>';
                        pushBotHtml(html);
                    });
                    // اگر رکورد بیشتری وجود دارد دکمه نمایش بیشتر نگه داشته شود
                    if (hasMore) {
                        $btn.attr('data-offset', nextOffsetVal)
                            .prop('disabled', false)
                            .text(defaultLabel);
                    } else {
                        $btn.remove();
                    }
                } else {
                    pushBotHtml('<div>📭 تجربه بیشتری برای این شغل ثبت نشده است.</div>');
                    $btn.remove();
                }
            }).fail(function(){
                $btn.prop('disabled', false).text(defaultLabel);
                pushBotHtml('<div style="color:#d32f2f;">خطا در دریافت تجربه‌های بیشتر.</div>');
            });
        });

        // menu handlers (باز و بسته کردن پنل)
        if(!window.BKJA_MENU_READY){
            window.BKJA_MENU_READY = true;
            var containers = document.querySelectorAll('#bkja-chatbox, .bkja-container');
            containers.forEach(function(container){
                var btn = container.querySelector('#bkja-menu-toggle, .bkja-menu-toggle');
                var panel = container.querySelector('#bkja-menu-panel, .bkja-menu-panel');
                var closeBtn = panel ? panel.querySelector('.bkja-close-menu') : null;
                if(!btn || !panel) return;
                btn.addEventListener('click', function(e){ 
                    e.stopPropagation(); 
                    panel.classList.add('bkja-open'); 
                    btn.setAttribute('aria-expanded','true'); 
                    // حذف مخفی‌سازی چت باکس هنگام باز شدن منو
                });
                if(closeBtn) closeBtn.addEventListener('click', function(e){ e.preventDefault(); panel.classList.remove('bkja-open'); btn.setAttribute('aria-expanded','false'); });
                document.addEventListener('click', function(e){
                    if(!panel.classList.contains('bkja-open')) return;
                    if(panel.contains(e.target) || btn.contains(e.target)) return;
                    panel.classList.remove('bkja-open');
                    btn.setAttribute('aria-expanded','false');
                });
            });
        }

        // Crisp-style Chat Launcher JS
        (function(window, document, $){
          $(function(){
            var $launcher = $('#bkja-chat-launcher');
            var $launcherBtn = $('#bkja-launcher-btn');
            var $welcome = $('#bkja-launcher-welcome');
            var $chatPanel = $('#bkja-chatbox');
            var $overlay = $('#bkja-chat-overlay');
            var $closePanel = $('#bkja-close-panel');
            var $messages = $('.bkja-messages');
            var welcomeMsg = 'سلام 👋 من دستیار شغلی هستم. چطور می‌تونم کمکتون کنم؟';
            var firstBotMsg = welcomeMsg;
            // Crisp-style launcher: show immediately
            $launcher.css({position:'fixed',bottom:'32px',right:'32px',zIndex:99999, pointerEvents:'auto'});
            // Welcome widget: show after short delay (200ms)
            $welcome.css({position:'fixed',bottom:'100px',right:'40px',zIndex:99999, pointerEvents:'auto'});
            setTimeout(function(){
                $welcome.addClass('bkja-show');
            }, 200);
            function lockBody(){
                document.body.classList.add('bkja-body-lock');
            }
            function unlockBody(){
                document.body.classList.remove('bkja-body-lock');
            }
            function openChat(){
                $chatPanel.removeClass('bkja-panel-hidden').addClass('bkja-panel-visible');
                $launcher.fadeOut(300);
                $overlay.addClass('bkja-active');
                lockBody();
                if ($messages.find('.bkja-bubble.bot').length === 0) {
                    pushBot(firstBotMsg);
                }
            }
            function closeChat(){
                $chatPanel.removeClass('bkja-panel-visible').addClass('bkja-panel-hidden');
                $launcher.fadeIn(300);
                $welcome.removeClass('bkja-show');
                $overlay.removeClass('bkja-active');
                unlockBody();
                setTimeout(function(){ $welcome.addClass('bkja-show'); }, 200);
            }
            // هندل کلیک و تاچ برای موبایل و دسکتاپ
            $welcome.on('click touchstart', function(e){
                e.preventDefault();
                openChat();
            });
            $launcherBtn.on('click touchstart', function(e){
                e.preventDefault();
                openChat();
            });
            // دکمه بستن چت باکس
            $closePanel.on('click touchstart', function(e){
                e.preventDefault();
                closeChat();
            });
            $overlay.on('click touchstart', function(e){
                e.preventDefault();
                if($chatPanel.hasClass('bkja-panel-visible')){
                    closeChat();
                }
            });
            // کلیک بیرون چت باکس
            $(document).on('mousedown touchstart', function(e){
                if($chatPanel.hasClass('bkja-panel-visible')){
                    if(!$(e.target).closest('#bkja-chatbox').length && !$(e.target).closest('#bkja-chat-launcher').length && !$(e.target).closest('#bkja-launcher-welcome').length){
                        closeChat();
                    }
                }
            });
          });
        })(window, document, jQuery);

    });

})(window, document, jQuery);
