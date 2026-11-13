'use strict';

const video = document.querySelector('#camera-preview');
const startButton = document.querySelector('#start-camera');
const stopButton = document.querySelector('#stop-camera');
const statusMessage = document.querySelector('#camera-status');
const takePhotoButton = document.querySelector('#take-photo');
const captureMessage = document.querySelector('#capture-message');
const captureResult = document.querySelector('#capture-result');
const finalPhoto = document.querySelector('#final-photo');
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

let cameraStream = null;
let captureBusy = false;
let photoUrl = null;

function updateTakePhotoButton() {
    const overlaySelected = document.querySelector('.overlay-radio:checked') !== null;
    takePhotoButton.disabled = cameraStream === null || stopButton.disabled || !overlaySelected || captureBusy;
}

function stopCamera() {
    if (cameraStream !== null) {
        cameraStream.getTracks().forEach((track) => track.stop());
        cameraStream = null;
    }

    video.pause();
    video.srcObject = null;
    startButton.disabled = false;
    stopButton.disabled = true;
    statusMessage.textContent = 'Camera is off.';
    updateTakePhotoButton();
}

async function startCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        statusMessage.textContent = 'Camera access is unavailable in this browser or connection.';
        return;
    }

    startButton.disabled = true;
    statusMessage.textContent = 'Requesting camera access…';

    try {
        cameraStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
        video.srcObject = cameraStream;
        await video.play();
        stopButton.disabled = false;
        statusMessage.textContent = 'Camera is on.';
        updateTakePhotoButton();
    } catch (error) {
        stopCamera();
        statusMessage.textContent = error && error.name === 'NotAllowedError'
            ? 'Camera permission was denied. Allow access in your browser and try again.'
            : 'The camera is unavailable. Check that it is connected and not in use.';
    }
}

startButton.addEventListener('click', startCamera);
stopButton.addEventListener('click', stopCamera);
window.addEventListener('pagehide', stopCamera);

const selectedOverlayImage = document.querySelector('#camera-overlay');
const overlayInputs = document.querySelectorAll('.overlay-radio');
const clearOverlayButton = document.querySelector('#clear-overlay');

overlayInputs.forEach((input) => {
    input.addEventListener('change', () => {
        if (input.checked) {
            selectedOverlayImage.src = input.dataset.src;
            selectedOverlayImage.hidden = false;
            clearOverlayButton.disabled = false;
            updateTakePhotoButton();
        }
    });
});

clearOverlayButton.addEventListener('click', () => {
    overlayInputs.forEach((input) => {
        input.checked = false;
    });
    selectedOverlayImage.hidden = true;
    selectedOverlayImage.removeAttribute('src');
    clearOverlayButton.disabled = true;
    updateTakePhotoButton();
});

async function takePhoto() {
    const selectedOverlay = document.querySelector('.overlay-radio:checked');
    if (cameraStream === null || stopButton.disabled || selectedOverlay === null) {
        return;
    }

    captureBusy = true;
    updateTakePhotoButton();
    captureMessage.hidden = true;

    try {
        const sourceWidth = video.videoWidth;
        const sourceHeight = video.videoHeight;
        if (sourceWidth === 0 || sourceHeight === 0) {
            throw new Error('Camera frame unavailable.');
        }

        const canvas = document.createElement('canvas');
        canvas.width = 640;
        canvas.height = 480;
        const context = canvas.getContext('2d');
        if (context === null) {
            throw new Error('Canvas unavailable.');
        }

        // Match the centered 4:3 crop shown by object-fit: cover.
        let cropWidth = sourceWidth;
        let cropHeight = sourceHeight;
        if (sourceWidth / sourceHeight > 4 / 3) {
            cropWidth = sourceHeight * 4 / 3;
        } else {
            cropHeight = sourceWidth * 3 / 4;
        }

        context.drawImage(
            video,
            (sourceWidth - cropWidth) / 2,
            (sourceHeight - cropHeight) / 2,
            cropWidth,
            cropHeight,
            0,
            0,
            canvas.width,
            canvas.height
        );

        const photo = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
        if (photo === null) {
            throw new Error('Photo encoding failed.');
        }

        const formData = new FormData();
        formData.append('photo', photo, 'capture.png');
        formData.append('overlay', selectedOverlay.value);
        formData.append('csrf_token', csrfToken);

        const response = await fetch('/capture.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        });

        if (!response.ok || !response.headers.get('content-type')?.startsWith('image/png')) {
            throw new Error('Photo processing failed.');
        }

        const result = await response.blob();
        if (result.size === 0) {
            throw new Error('Empty photo returned.');
        }

        if (photoUrl !== null) {
            URL.revokeObjectURL(photoUrl);
        }
        photoUrl = URL.createObjectURL(result);
        finalPhoto.src = photoUrl;
        finalPhoto.alt = `Captured photo with ${selectedOverlay.value} overlay`;
        captureResult.hidden = false;
    } catch {
        captureMessage.textContent = 'Could not capture or process the photo. Please try again.';
        captureMessage.hidden = false;
    } finally {
        captureBusy = false;
        updateTakePhotoButton();
    }
}

takePhotoButton.addEventListener('click', takePhoto);
window.addEventListener('pagehide', () => {
    if (photoUrl !== null) {
        URL.revokeObjectURL(photoUrl);
    }
});
