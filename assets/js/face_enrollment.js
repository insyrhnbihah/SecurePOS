(function () {
    'use strict';

    var config = window.securePosFaceEnrollment || {};
    var modal = document.getElementById('face-enrollment-modal');
    var video = document.getElementById('face-enrollment-video');
    var message = document.getElementById('face-enrollment-message');
    var captureButton = document.getElementById('capture-face');
    var closeButton = document.getElementById('close-face-enrollment');
    var cancelButton = document.getElementById('cancel-face-enrollment');
    var targetLabel = document.getElementById('face-enrollment-user');
    var enrollmentButtons = document.querySelectorAll('.face-enroll-button:not(:disabled)');
    var stream = null;
    var targetUserId = null;
    var modelsReady = false;

    function setMessage(text, isError) {
        message.textContent = text;
        message.classList.toggle('error', Boolean(isError));
    }

    function stopCamera() {
        if (stream) {
            stream.getTracks().forEach(function (track) { track.stop(); });
            stream = null;
        }
        video.srcObject = null;
    }

    function closeEnrollment() {
        stopCamera();
        modal.classList.remove('visible');
        document.body.classList.remove('users-modal-open');
        captureButton.disabled = true;
        targetUserId = null;
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

    async function openEnrollment(button) {
        targetUserId = Number(button.getAttribute('data-user-id'));
        targetLabel.textContent = 'Enrolling face verification for ' + button.getAttribute('data-user-name') + '.';
        modal.classList.add('visible');
        document.body.classList.add('users-modal-open');
        setMessage('Loading face detection models...', false);
        try {
            await loadModels();
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                throw new Error('Camera access requires localhost or HTTPS.');
            }
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
            video.srcObject = stream;
            await video.play();
            captureButton.disabled = false;
            setMessage('Position exactly one face in the frame, then capture.', false);
        } catch (error) {
            stopCamera();
            captureButton.disabled = true;
            setMessage(error && error.message === 'Camera access requires localhost or HTTPS.' ? error.message : 'Camera access or face detection is unavailable.', true);
        }
    }

    async function captureAndEnroll() {
        if (!targetUserId || !modelsReady || !stream) { return; }
        captureButton.disabled = true;
        setMessage('Detecting face...', false);
        try {
            var detections = await faceapi
                .detectAllFaces(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 }))
                .withFaceLandmarks()
                .withFaceDescriptors();
            if (detections.length === 0) {
                setMessage('No face was detected. Please adjust your position and try again.', true);
                captureButton.disabled = false;
                return;
            }
            if (detections.length !== 1) {
                setMessage('More than one face was detected. Only one person may be in frame.', true);
                captureButton.disabled = false;
                return;
            }
            var response = await fetch(config.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrfToken: config.csrfToken,
                    userId: targetUserId,
                    descriptor: Array.from(detections[0].descriptor)
                })
            });
            var result = await response.json();
            if (!result || !result.success) {
                throw new Error(result && result.message ? result.message : 'Unable to save face enrollment.');
            }
            stopCamera();
            setMessage(result.message, false);
            window.setTimeout(function () { window.location.reload(); }, 700);
        } catch (error) {
            setMessage(error && error.message ? error.message : 'Unable to save face enrollment.', true);
            captureButton.disabled = false;
        }
    }

    Array.prototype.forEach.call(enrollmentButtons, function (button) {
        button.addEventListener('click', function () { openEnrollment(button); });
    });
    captureButton.addEventListener('click', captureAndEnroll);
    closeButton.addEventListener('click', closeEnrollment);
    cancelButton.addEventListener('click', closeEnrollment);
    modal.addEventListener('click', function (event) { if (event.target === modal) { closeEnrollment(); } });
    window.addEventListener('beforeunload', stopCamera);
}());
