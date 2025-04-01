document.addEventListener("DOMContentLoaded", function() {
    const SECRET_KEY = "your-secret-key-123";
    let currentPlayer = null; // 'jwplayer', 'shaka', 'iframe'
    let playerInstance = null;
    let shakaPlayer = null;
    let shakaUI = null;

    function encryptURL(url) {
        return CryptoJS.AES.encrypt(url, SECRET_KEY).toString();
    }

    function decryptURL(encryptedURL) {
        const bytes = CryptoJS.AES.decrypt(encryptedURL, SECRET_KEY);
        return bytes.toString(CryptoJS.enc.Utf8);
    }

    const themeToggle = document.getElementById("theme-toggle");
    const channelsList = document.getElementById("channels-list");
    const searchInput = document.getElementById("search-input");
    const clearSearch = document.getElementById("clear-search");
    const matchesButton = document.getElementById("matches-button");
    const playerContainer = document.getElementById("player-container");
    const playerPlaceholder = document.getElementById("player-placeholder");
    const channelsSidebar = document.getElementById("channels-sidebar");
    const channelsToggle = document.getElementById("channels-toggle");
    const closeSidebar = document.getElementById("close-sidebar");
    const matchesDialog = document.getElementById("matches-dialog");
    const closeMatchesDialog = document.getElementById("close-matches-dialog");

    // إخفاء القائمة عند النقر خارجها
    document.addEventListener("click", function(event) {
        if (!channelsSidebar.contains(event.target) && !channelsToggle.contains(event.target)) {
            channelsSidebar.style.display = "none";
        }
    });

    // تبديل الثيم
    themeToggle.addEventListener("click", function() {
        document.body.classList.toggle("dark-theme");
        themeToggle.innerHTML = document.body.classList.contains("dark-theme") ? 
            '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    });

    async function fetchFromPleaxy(channelId) {
        try {
            const pleaxyUrl = `https://pleaxy.com/test/proxy.php?channel=${channelId}`;
            const response = await fetch(pleaxyUrl);
            
            if (!response.ok) {
                throw new Error(`فشل الاتصال بـ Pleaxy: ${response.status}`);
            }
            
            const streamUrl = await response.text();
            
            if (!streamUrl || !streamUrl.trim()) {
                throw new Error("رابط البث فارغ من Pleaxy");
            }
            
            const trimmedUrl = streamUrl.trim();
            
            if (!/^(https?:)?\/\//i.test(trimmedUrl)) {
                throw new Error("رابط البث غير صالح من Pleaxy");
            }
            
            return trimmedUrl;
        } catch (error) {
            console.error(`حدث خطأ أثناء جلب الرابط من Pleaxy للقناة ${channelId}:`, error);
            return null;
        }
    }

    async function fetchStreamAndKeys(apiUrl) {
        try {
            const response = await fetch(apiUrl);
            if (!response.ok) {
                throw new Error("فشل في جلب البيانات من الرابط");
            }
            const data = await response.json();

            const streamUrl = data.stream_url;
            const drmKeys = data.drm_keys;

            if (!streamUrl) {
                throw new Error("رابط البث غير موجود في البيانات");
            }

            let keysString = null;
            if (drmKeys && Object.keys(drmKeys).length > 0) {
                keysString = Object.entries(drmKeys)
                    .map(([keyId, key]) => `${keyId}:${key}`)
                    .join(",");
            }

            return { streamUrl, keysString };
        } catch (error) {
            console.error("حدث خطأ أثناء جلب البيانات:", error);
            return null;
        }
    }

    function loadIframe(url) {
        closeJWPlayer();
        closeShakaPlayer();
        currentPlayer = 'iframe';
        
        const playerElement = document.getElementById("player");
        if (!playerElement) {
            console.error("عنصر المشغل غير موجود في الصفحة.");
            return;
        }
        
        playerElement.innerHTML = `
            <iframe src="${url}" 
                    frameborder="0" 
                    allowfullscreen 
                    style="width:100%; height:100%;">
            </iframe>
        `;
        
        console.log("تم تحميل iframe بنجاح");
    }

    function loadChannels() {
        channelsList.innerHTML = "";
        db.collection("groups").orderBy("createdAt", "asc").get().then((groupsSnapshot) => {
            groupsSnapshot.forEach((groupDoc) => {
                const group = groupDoc.data();
                const groupSection = document.createElement("div");
                groupSection.classList.add("group-section");
                groupSection.setAttribute("data-group-id", groupDoc.id);
                groupSection.innerHTML = `
                    <h3 class="group-name">${group.name}</h3>
                    <div class="channels-container"></div>
                `;
                channelsList.appendChild(groupSection);

                const channelsContainer = groupSection.querySelector(".channels-container");

                db.collection("channels").where("group", "==", groupDoc.id).orderBy("createdAt", "asc").get().then((channelsSnapshot) => {
                    channelsSnapshot.forEach((channelDoc) => {
                        const channel = channelDoc.data();
                        const channelCard = document.createElement("div");
                        channelCard.classList.add("channel-card");
                        channelCard.innerHTML = `
                            <img src="${channel.image}" alt="${channel.name}">
                            <p>${channel.name}</p>
                        `;
                        const encryptedURL = encryptURL(channel.url);
                        channelCard.setAttribute("data-url", encryptedURL);
                        channelCard.setAttribute("data-key", channel.key || "");
                        channelsContainer.appendChild(channelCard);

                        channelCard.addEventListener("click", function() {
                            const encryptedURL = channelCard.getAttribute("data-url");
                            const key = channelCard.getAttribute("data-key");
                            const decryptedURL = decryptURL(encryptedURL);
                            playChannel(decryptedURL, key);
                            channelsSidebar.style.display = "none";
                        });
                    });
                });
            });
        });
    }

    function playWithShakaPlayer(url, keys) {
        const playerElement = document.getElementById("player");
        if (!playerElement) {
            console.error("عنصر المشغل غير موجود في الصفحة.");
            return;
        }

        currentPlayer = 'shaka';
        
        // تحويل مفاتيح DRM إلى التنسيق المطلوب
        const drmKeys = {};
        if (keys) {
            keys.split(',').forEach(keyPair => {
                const [keyId, key] = keyPair.split(':');
                drmKeys[keyId] = key;
            });
        }

        // إنشاء عناصر مشغل Shaka مع الخلفية المخصصة
        playerElement.innerHTML = `
            <div class="shaka-video-container" style="position:absolute;top:0;left:0;width:100%;height:100%;">
                <video autoplay data-shaka-player id="shaka-video" style="width:100%;height:100%;object-fit:fill;"></video>
            </div>
            <div id="shaka-loading-spinner" class="loading-spinner" style="display:flex;">
                <div class="spinner"></div>
                <div class="loading-text">.... جاري تحميل البث</div>
            </div>
            <div class="shaka-background" style="position:absolute;top:0;left:0;width:100%;height:100%;z-index:-1;">
                <img src="" style="width:100%;height:100%;object-fit:fill">
            </div>
        `;

        const video = document.getElementById('shaka-video');
        const loadingSpinner = document.getElementById('shaka-loading-spinner');

        // تكوين مشغل Shaka مع واجهة مخصصة
        const config = {
            drm: {
                clearKeys: drmKeys
            },
            abr: {
                enabled: true,
                defaultBandwidthEstimate: 5000000,
                restrictions: {
                    minHeight: 480,
                    maxHeight: 1080
                }
            },
            streaming: {
                bufferingGoal: 60,
                rebufferingGoal: 2,
                bufferBehind: 30
            },
            ui: {
                controlPanelElements: [
                    'rewind','play_pause','fast_forward','mute','volume','time_and_duration','spacer','captions','language','quality','playback_rate','fullscreen','picture_in_picture','skip','cast','airplay'
                ],
                seekBarColors: {
                    base: '#00EC00',
                    buffered: '#ffffff',
                    played: '#ff5722'
                },
                 addSeekBar: true,
            addBigPlayButton: false
        },
        // تفعيل الترجمة تلقائياً
        preferredTextLanguage: 'ar', // تفضيل اللغة العربية
        textTrackDisplay: true, // إظهار الترجمة
        enableTextTrackOnStart: true // تفعيل الترجمة عند البدء
    };

        // تهيئة المشغل
        shaka.polyfill.installAll();
        shakaPlayer = new shaka.Player(video);
        shakaUI = new shaka.ui.Overlay(shakaPlayer, video.parentElement, video);
        
        shakaPlayer.configure(config);
        shakaUI.configure(config.ui);

        // معالجة الأخطاء
        shakaPlayer.addEventListener('error', (error) => {
            console.error('Shaka Player error:', error);
            loadingSpinner.querySelector('.loading-text').textContent = 'حدث خطأ في تحميل البث، جاري إعادة المحاولة...';
            setTimeout(() => {
                playWithShakaPlayer(url, keys);
            }, 5000);
        });

        // تحميل الفيديو
        shakaPlayer.load(url).then(() => {
            console.log('تم تحميل الفيديو بنجاح');
            loadingSpinner.style.display = 'none';
            
            // تفعيل Fullscreen تلقائيًا على أجهزة Android الذكية
            if (isAndroidSmartTV()) {
                setTimeout(() => {
                    enterFullscreen();
                }, 1000);
            }
        }).catch(error => {
            console.error('فشل تحميل الفيديو:', error);
            loadingSpinner.querySelector('.loading-text').textContent = 'حدث خطأ في تحميل البث، جاري إعادة المحاولة...';
        });
    }

    function closeJWPlayer() {
        if (playerInstance && playerInstance.remove) {
            playerInstance.remove();
            playerInstance = null;
        }
        currentPlayer = null;
    }

    function closeShakaPlayer() {
        const playerElement = document.getElementById("player");
        if (playerElement) {
            playerElement.innerHTML = "";
        }
        if (shakaPlayer) {
            shakaPlayer.destroy();
            shakaPlayer = null;
        }
        if (shakaUI) {
            shakaUI.destroy();
            shakaUI = null;
        }
        currentPlayer = null;
    }

    // دالة للكشف عن أجهزة Android الذكية
    function isAndroidSmartTV() {
        const userAgent = navigator.userAgent.toLowerCase();
        const isAndroid = /android/.test(userAgent);
        const isTV = /smart-tv|smarttv|googletv|appletv|hbbtv|pov_tv|netcast|boxee|kylo|roku|dlnadoc|ce-html|smarttv|web0s|webos|tv|tizen|tv|playstation|xbox/.test(userAgent);
        return isAndroid && isTV;
    }

    // دالة لتفعيل وضع ملء الشاشة
    function enterFullscreen() {
        const playerElement = document.getElementById("player");
        if (!playerElement) return;

        if (playerElement.requestFullscreen) {
            playerElement.requestFullscreen();
        } else if (playerElement.webkitRequestFullscreen) { /* Safari */
            playerElement.webkitRequestFullscreen();
        } else if (playerElement.msRequestFullscreen) { /* IE11 */
            playerElement.msRequestFullscreen();
        }
    }

    // دالة للخروج من وضع ملء الشاشة
    function exitFullscreen() {
        if (document.exitFullscreen) {
            document.exitFullscreen();
        } else if (document.webkitExitFullscreen) { /* Safari */
            document.webkitExitFullscreen();
        } else if (document.msExitFullscreen) { /* IE11 */
            document.msExitFullscreen();
        }
    }

    // دالة للتحقق من وضع ملء الشاشة
    function isFullscreen() {
        return document.fullscreenElement || 
               document.webkitFullscreenElement || 
               document.msFullscreenElement;
    }

    // اختصارات لوحة المفاتيح والريموت كنترول
    function setupKeyboardShortcuts() {
        document.addEventListener('keydown', function(event) {
            // تجاهل الأحداث إذا كان المستخدم يتفاعل مع عناصر الإدخال
            if (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA') {
                return;
            }

            switch(event.key) {
                case ' ':
                case 'k':
                    // مسافة أو حرف k للتشغيل/الإيقاف
                    togglePlayPause();
                    event.preventDefault();
                    break;
                case 'f':
                    // حرف f لتبديل وضع ملء الشاشة
                    toggleFullscreen();
                    event.preventDefault();
                    break;
                case 'm':
                    // حرف m لكتم/إلغاء كتم الصوت
                    toggleMute();
                    event.preventDefault();
                    break;
                case 'ArrowRight':
                    // السهم الأيمن للتقدم 10 ثواني
                    seekForward(10);
                    event.preventDefault();
                    break;
                case 'ArrowLeft':
                    // السهم الأيسر للتراجع 10 ثواني
                    seekBackward(10);
                    event.preventDefault();
                    break;
                case 'ArrowUp':
                    // السهم لأعلى لزيادة الصوت
                    increaseVolume();
                    event.preventDefault();
                    break;
                case 'ArrowDown':
                    // السهم لأسفل لتقليل الصوت
                    decreaseVolume();
                    event.preventDefault();
                    break;
                case '0':
                case '1':
                case '2':
                case '3':
                case '4':
                case '5':
                case '6':
                case '7':
                case '8':
                case '9':
                    // الأرقام من 0-9 للقفز إلى نسبة مئوية من الفيديو
                    seekToPercentage(parseInt(event.key) * 10);
                    event.preventDefault();
                    break;
            }
        });

        // اختصارات الريموت كنترول (لأجهزة التلفزيون الذكية)
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                // زر Enter للتشغيل/الإيقاف
                togglePlayPause();
                event.preventDefault();
            } else if (event.key === 'Backspace' || event.key === 'Escape') {
                // زر الرجوع أو Escape للخروج من ملء الشاشة
                if (isFullscreen()) {
                    exitFullscreen();
                    event.preventDefault();
                }
            }
        });
    }

    // وظائف مساعدة لاختصارات لوحة المفاتيح
    function togglePlayPause() {
        switch(currentPlayer) {
            case 'jwplayer':
                if (playerInstance.getState() === 'playing') {
                    playerInstance.pause();
                } else {
                    playerInstance.play();
                }
                break;
            case 'shaka':
                if (shakaPlayer && shakaPlayer.isLive()) {
                    // لا يوجد تشغيل/إيقاف للبث المباشر
                } else if (shakaPlayer) {
                    if (shakaPlayer.getMediaElement().paused) {
                        shakaPlayer.getMediaElement().play();
                    } else {
                        shakaPlayer.getMediaElement().pause();
                    }
                }
                break;
            case 'iframe':
                // لا يوجد تحكم في iframe
                break;
        }
    }

    function toggleFullscreen() {
        if (isFullscreen()) {
            exitFullscreen();
        } else {
            enterFullscreen();
        }
    }

    function toggleMute() {
        switch(currentPlayer) {
            case 'jwplayer':
                playerInstance.setMute(!playerInstance.getMute());
                break;
            case 'shaka':
                if (shakaPlayer) {
                    const video = shakaPlayer.getMediaElement();
                    video.muted = !video.muted;
                }
                break;
            case 'iframe':
                // لا يوجد تحكم في iframe
                break;
        }
    }

    function seekForward(seconds) {
        switch(currentPlayer) {
            case 'jwplayer':
                playerInstance.seek(playerInstance.getPosition() + seconds);
                break;
            case 'shaka':
                if (shakaPlayer) {
                    const video = shakaPlayer.getMediaElement();
                    video.currentTime += seconds;
                }
                break;
            case 'iframe':
                // لا يوجد تحكم في iframe
                break;
        }
    }

    function seekBackward(seconds) {
        switch(currentPlayer) {
            case 'jwplayer':
                playerInstance.seek(playerInstance.getPosition() - seconds);
                break;
            case 'shaka':
                if (shakaPlayer) {
                    const video = shakaPlayer.getMediaElement();
                    video.currentTime -= seconds;
                }
                break;
            case 'iframe':
                // لا يوجد تحكم في iframe
                break;
        }
    }

    function increaseVolume() {
        switch(currentPlayer) {
            case 'jwplayer':
                playerInstance.setVolume(Math.min(playerInstance.getVolume() + 0.1, 1));
                break;
            case 'shaka':
                if (shakaPlayer) {
                    const video = shakaPlayer.getMediaElement();
                    video.volume = Math.min(video.volume + 0.1, 1);
                }
                break;
            case 'iframe':
                // لا يوجد تحكم في iframe
                break;
        }
    }

    function decreaseVolume() {
        switch(currentPlayer) {
            case 'jwplayer':
                playerInstance.setVolume(Math.max(playerInstance.getVolume() - 0.1, 0));
                break;
            case 'shaka':
                if (shakaPlayer) {
                    const video = shakaPlayer.getMediaElement();
                    video.volume = Math.max(video.volume - 0.1, 0);
                }
                break;
            case 'iframe':
                // لا يوجد تحكم في iframe
                break;
        }
    }

    function seekToPercentage(percentage) {
        switch(currentPlayer) {
            case 'jwplayer':
                const duration = playerInstance.getDuration();
                playerInstance.seek((duration * percentage) / 100);
                break;
            case 'shaka':
                if (shakaPlayer) {
                    const video = shakaPlayer.getMediaElement();
                    video.currentTime = (video.duration * percentage) / 100;
                }
                break;
            case 'iframe':
                // لا يوجد تحكم في iframe
                break;
        }
    }

    async function playChannel(url, key) {
        if (url.endsWith('.html')) {
            loadIframe(url);
            return;
        }
        
        if (url.includes("api.php")) {
            const streamData = await fetchStreamAndKeys(url);
            if (!streamData) return;
            url = streamData.streamUrl;
            key = streamData.keysString;
        }
        else if (url.includes("pleaxy.com")) {
            const channelId = new URL(url).searchParams.get("channel");
            if (channelId) {
                const pleaxyUrl = await fetchFromPleaxy(channelId);
                if (pleaxyUrl) {
                    url = pleaxyUrl;
                } else {
                    console.error("فشل في الحصول على رابط من Pleaxy");
                    return;
                }
            }
        }

        closeJWPlayer();
        closeShakaPlayer();

        if (!url) {
            console.error("الرابط غير موجود!");
            return;
        }

        let finalUrl = url;
        let streamType = getStreamType(url);

        if (url.includes('youtube.com') || url.includes('youtu.be')) {
            const youtubeStreamUrl = await fetchFromYouTube(url);
            if (youtubeStreamUrl) {
                finalUrl = youtubeStreamUrl;
                streamType = getStreamType(youtubeStreamUrl);
            }
        }

        if (!streamType) {
            console.error("نوع الملف غير مدعوم أو غير معروف:", finalUrl);
            return;
        }

        if (key && key.includes(',')) {
            playWithShakaPlayer(finalUrl, key);
            return;
        }

        const drmConfig = key ? {
            clearkey: {
                keyId: key.split(':')[0],
                key: key.split(':')[1]
            },
            robustness: 'SW_SECURE_CRYPTO'
        } : null;

        const playerElement = document.getElementById("player");
        if (!playerElement) return;

        try {
            playerInstance = jwplayer("player").setup({
                playlist: [{
                    sources: [{
                        file: finalUrl,
                        type: streamType,
                        drm: drmConfig
                    }]
                }],
                width: "100%",
                height: "100%",
                autostart: true,
                cast: {
                    default: true
                },
                sharing: false,
                controls: true,
                stretching: "fill",
                horizontalVolumeSlider: true,
                preload: "auto",
                playbackRateControls: true,
                primary: "html5",
                mute: false,
                volume: 80,
                logo: {
                    file: "https://up6.cc/2025/03/174177781485261.png",
                    link: "https://t.me/moviball",
                    hide: false,
                    position: "bottom-left",
                    margin: 28,
                    width: 5,
                    height: 5
                }
            });

            currentPlayer = 'jwplayer';

            playerInstance.on('ready', function() {
                const logoElement = document.querySelector(".jw-logo");
                if (logoElement) {
                    logoElement.style.position = "fixed";
                    logoElement.style.bottom = "5%";
                    logoElement.style.left = "0.5%";
                    logoElement.style.opacity = "0";
                    logoElement.style.transition = "none";
                }

                function updateLogoOpacity() {
                    let quality = playerInstance.getVisualQuality();
                    if (quality && quality.level) {
                        let currentHeight = quality.level.height || 480;
                        let opacity = 0.2 + ((currentHeight - 240) / (1080 - 240)) * (0.8 - 0.2);
                        if (currentHeight > 1080) opacity = 0.9;
                        if (logoElement) {
                            logoElement.style.opacity = opacity.toFixed(2);
                        }
                    }
                }

                playerInstance.on("firstFrame", function() {
                    if (logoElement) logoElement.style.opacity = "1";
                    updateLogoOpacity();
                });

                playerInstance.on("visualQuality", updateLogoOpacity);
                playerInstance.on("levelsChanged", updateLogoOpacity);

                // تفعيل Fullscreen تلقائيًا على أجهزة Android الذكية
                if (isAndroidSmartTV()) {
                    setTimeout(() => {
                        playerInstance.setFullscreen(true);
                    }, 1000);
                }
            });

            playerInstance.on('error', function(error) {
                console.error("حدث خطأ في المشغل:", error);
            });

            playerInstance.on('setupError', function(error) {
                console.error("حدث خطأ في إعداد المشغل:", error);
            });

            playerInstance.on("fullscreen", function(event) {
                if (event.fullscreen) {
                    screen.orientation.lock("landscape").catch(function() {
                        console.warn("لم يتم دعم تأمين الشاشة في هذا المتصفح.");
                    });
                } else {
                    screen.orientation.unlock();
                }
            });
        } catch (error) {
            console.error("حدث خطأ أثناء إعداد المشغل:", error);
        }
    }

    function getStreamType(url) {
        if (url.includes(".m3u8")) return "hls";
        if (url.includes(".mpd")) return "dash";
        if (url.includes(".mp4") || url.includes(".m4v")) return "mp4";
        if (url.includes(".ts") || url.includes(".mpegts")) return "mpegts";
        return null;
    }

    // تهيئة اختصارات لوحة المفاتيح
    setupKeyboardShortcuts();

    // باقي الدوال والاستماع للأحداث
    searchInput.addEventListener("input", function() {
        const searchTerm = searchInput.value.toLowerCase();
        document.querySelectorAll(".group-section").forEach(function(group) {
            const groupName = group.querySelector(".group-name").textContent.toLowerCase();
            const channels = group.querySelectorAll(".channel-card");
            let hasVisibleChannels = false;

            channels.forEach(function(channel) {
                const channelName = channel.querySelector("p").textContent.toLowerCase();
                if (channelName.includes(searchTerm)) {
                    channel.style.display = "flex";
                    hasVisibleChannels = true;
                } else {
                    channel.style.display = "none";
                }
            });

            group.style.display = hasVisibleChannels || groupName.includes(searchTerm) ? "block" : "none";
        });
    });

    clearSearch.addEventListener("click", function() {
        searchInput.value = "";
        document.querySelectorAll(".group-section, .channel-card").forEach(function(element) {
            element.style.display = "block";
        });
    });

    channelsToggle.addEventListener("click", function() {
        channelsSidebar.style.display = "block";
    });

    closeSidebar.addEventListener("click", function() {
        channelsSidebar.style.display = "none";
    });

    matchesButton.addEventListener("click", function() {
        matchesDialog.style.display = "block";
        loadMatches();
    });

    closeMatchesDialog.addEventListener("click", function() {
        matchesDialog.style.display = "none";
    });

    function loadMatches() {
        const matchesTable = document.getElementById("matches-table");
        matchesTable.innerHTML = "";

        db.collection("matches").orderBy("createdAt", "asc").get().then(function(querySnapshot) {
            querySnapshot.forEach(function(doc) {
                const match = doc.data();
                const matchItem = document.createElement("div");
                matchItem.classList.add("match-item");

                const team1Image = match.team1Image;
                const team2Image = match.team2Image;
                const matchTimeUTC = new Date(match.matchTime);
                const currentTimeUTC = new Date();

                const timeDiff = (currentTimeUTC - matchTimeUTC) / (1000 * 60);

                let matchStatus = "";
                let matchStatusClass = "";
                if (timeDiff < -15) {
                    matchStatus = `الوقت: ${matchTimeUTC.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
                    matchStatusClass = "match-status";
                } else if (timeDiff >= -15 && timeDiff < 0) {
                    matchStatus = "تبدأ قريبًا";
                    matchStatusClass = "match-status soon";
                } else if (timeDiff >= 0 && timeDiff < 120) {
                    matchStatus = "جارية الآن";
                    matchStatusClass = "match-status live";
                } else {
                    db.collection("matches").doc(doc.id).delete();
                    return;
                }

                matchItem.innerHTML = `
                    <div class="teams-section">
                        <div class="team">
                            <img src="${team1Image}" alt="${match.team1}">
                            <p>${match.team1}</p>
                        </div>
                        <div class="vs-time">
                            <div class="vs">VS</div>
                            <div class="${matchStatusClass}">${matchStatus}</div>
                        </div>
                        <div class="team">
                            <img src="${team2Image}" alt="${match.team2}">
                            <p>${match.team2}</p>
                        </div>
                    </div>
                    <div class="match-details">
                        <p><span class="icon">🏆</span> ${match.matchLeague}</p>
                        <p><span class="icon">🎤</span> ${match.commentator}</p>
                    </div>
                    <button class="watch-button ${timeDiff >= -15 && timeDiff < 120 ? 'active' : 'inactive'}" data-url="${encryptURL(match.channelUrl)}" data-key="${match.key || ''}" ${timeDiff >= -15 && timeDiff < 120 ? '' : 'disabled'}>
                        مشاهدة المباراة
                    </button>
                `;

                matchesTable.appendChild(matchItem);
            });

            document.querySelectorAll(".watch-button").forEach(function(button) {
                button.addEventListener("click", function() {
                    const encryptedURL = button.getAttribute("data-url");
                    const key = button.getAttribute("data-key");
                    const decryptedURL = decryptURL(encryptedURL);
                    playChannel(decryptedURL, key);
                    matchesDialog.style.display = "none";
                    if (playerPlaceholder) playerPlaceholder.style.display = "none";
                });
            });
        });
    }

    loadChannels();
});
