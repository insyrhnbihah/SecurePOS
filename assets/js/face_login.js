(function () {
    'use strict';

    var config = window.securePosFaceLogin || {};
    var emailInput = document.getElementById('email');
    var openButton = document.getElementById('open-face-login');
    var modal = document.getElementById('face-login-modal');
    var video = document.getElementById('face-login-video');
    var landmarkCanvas = document.getElementById('face-landmarks');
    var message = document.getElementById('face-login-message');
    var statusTitle = document.getElementById('face-login-status-title');
    var statusDetail = document.getElementById('face-login-status-detail');
    var closeButton = document.getElementById('close-face-login');
    var cancelButton = document.getElementById('cancel-face-login');
    var stream = null;
    var modelsReady = false;
    var detectionTimer = null;
    var detectionInProgress = false;
    var isVerifying = false;
    var closeTimer = null;
    var INITIAL_DETECTION_DELAY_MS = 100;
    var DETECTION_POLL_DELAY_MS = 250;
    var SCANNING_TRANSITION_DELAY_MS = 120;

    function setState(state, title, detail, isError) {
        modal.setAttribute('data-face-state', state);
        statusTitle.textContent = title;
        statusDetail.textContent = detail;
        message.classList.toggle('error', Boolean(isError));
    }

    function clearLandmarks() {
        var context = landmarkCanvas.getContext('2d');
        context.clearRect(0, 0, landmarkCanvas.width, landmarkCanvas.height);
    }

    function drawActualLandmarks(detection) {
        var width = landmarkCanvas.clientWidth;
        var height = landmarkCanvas.clientHeight;
        if (!width || !height) { return; }
        landmarkCanvas.width = width;
        landmarkCanvas.height = height;
        var resized = faceapi.resizeResults(detection, { width: width, height: height });
        var context = landmarkCanvas.getContext('2d');
        context.clearRect(0, 0, width, height);
        context.fillStyle = 'rgba(116, 229, 235, .55)';
        resized.landmarks.positions.forEach(function (point) {
            context.beginPath();
            context.arc(point.x, point.y, 1.15, 0, Math.PI * 2);
            context.fill();
        });
    }

    function stopDetection() {
        window.clearTimeout(detectionTimer);
        detectionTimer = null;
        detectionInProgress = false;
    }

    function stopCamera() {
        stopDetection();
        if (stream) {
            stream.getTracks().forEach(function (track) { track.stop(); });
            stream = null;
        }
        video.srcObject = null;
        clearLandmarks();
    }

    function closeModal() {
        if (isVerifying) { return; }
        stopCamera();
        modal.classList.add('closing');
        document.body.classList.remove('face-login-open');
        window.clearTimeout(closeTimer);
        closeTimer = window.setTimeout(function () {
            modal.classList.remove('visible', 'closing');
            modal.removeAttribute('data-face-state');
        }, 180);
    }

    async function loadModels() {
        if (modelsReady) { return; }
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(config.modelPath),
            faceapi.nets.faceLandmark68Net.loadFromUri(config.modelPath),
            faceapi.nets.faceRecognitionNet.loadFromUri(config.modelPath)
        ]);
        modelsReady = true;
    }

    function scheduleDetection(delay) {
        if (!stream || isVerifying) { return; }
        window.clearTimeout(detectionTimer);
        detectionTimer = window.setTimeout(detectAndVerify, delay);
    }

    function resetForRetry() {
        isVerifying = false;
        clearLandmarks();
        if (!stream) { return; }
        setState('positioning', 'Position your face', 'Move your face inside the frame', false);
        scheduleDetection(DETECTION_POLL_DELAY_MS);
    }

    async function submitDescriptor(descriptor) {
        setState('verifying', 'Verifying identity...', 'Please wait a moment', false);
        try {
            var response = await fetch(config.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrfToken: config.csrfToken,
                    email: emailInput.value.trim(),
                    descriptor: Array.from(descriptor)
                })
            });
            var result = await response.json();
            if (!result || !result.success || !result.redirect) {
                throw new Error('Face verification rejected.');
            }
            stopCamera();
            setState('success', 'Face Recognised', 'Identity verified — signing you in', false);
            window.setTimeout(function () { window.location.assign(result.redirect); }, 900);
        } catch (error) {
            setState('error', 'Face Not Recognised', 'Please position your face and try again', true);
            window.setTimeout(resetForRetry, 1400);
        }
    }

    async function detectAndVerify() {
        if (detectionInProgress || isVerifying || !modelsReady || !stream) { return; }
        detectionInProgress = true;
        try {
            var detections = await faceapi
                .detectAllFaces(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 }))
                .withFaceLandmarks()
                .withFaceDescriptors();

            if (detections.length !== 1) {
                clearLandmarks();
                setState('positioning', 'Position your face', detections.length > 1 ? 'Only one person may be inside the frame' : 'Move your face inside the frame', false);
                detectionInProgress = false;
                scheduleDetection(DETECTION_POLL_DELAY_MS);
                return;
            }

            isVerifying = true;
            drawActualLandmarks(detections[0]);
            setState('scanning', 'Scanning...', 'Keep still while we verify your identity', false);
            detectionInProgress = false;
            window.setTimeout(function () { submitDescriptor(detections[0].descriptor); }, SCANNING_TRANSITION_DELAY_MS);
        } catch (error) {
            detectionInProgress = false;
            setState('error', 'Face detection unavailable', 'Please check your camera and try again', true);
            window.setTimeout(resetForRetry, 1400);
        }
    }

    async function openModal() {
        if (!emailInput.checkValidity()) {
            emailInput.reportValidity();
            return;
        }
        window.clearTimeout(closeTimer);
        modal.classList.remove('closing');
        modal.classList.add('visible');
        document.body.classList.add('face-login-open');
        setState('loading', 'Preparing camera...', 'Loading secure face detection', false);
        try {
            await loadModels();
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                throw new Error('Camera access requires localhost or HTTPS.');
            }
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
            video.srcObject = stream;
            await video.play();
            setState('positioning', 'Position your face', 'Move your face inside the frame', false);
            scheduleDetection(INITIAL_DETECTION_DELAY_MS);
        } catch (error) {
            stopCamera();
            setState('error', 'Camera unavailable', error && error.message === 'Camera access requires localhost or HTTPS.' ? error.message : 'Camera access or face detection is unavailable.', true);
        }
    }

    openButton.addEventListener('click', openModal);
    closeButton.addEventListener('click', closeModal);
    cancelButton.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) { if (event.target === modal) { closeModal(); } });
    window.addEventListener('beforeunload', stopCamera);
}());
