'use strict';

const video = document.querySelector('#camera-preview');
const uploadPreview = document.querySelector('#upload-preview');
const uploadInput = document.querySelector('#upload-image');
const uploadControls = document.querySelector('#upload-controls');
const webcamOption = document.querySelector('#source-webcam');
const uploadOption = document.querySelector('#source-upload');
const cameraButtons = document.querySelector('#camera-buttons');
const startButton = document.querySelector('#start-camera');
const stopButton = document.querySelector('#stop-camera');
const statusMessage = document.querySelector('#camera-status');
const takePhotoButton = document.querySelector('#take-photo');
const captureMessage = document.querySelector('#capture-message');
const captureResult = document.querySelector('#capture-result');
const finalPhoto = document.querySelector('#final-photo');
const captureSuccess = document.querySelector('#capture-success');
const selectedOverlayImage = document.querySelector('#camera-overlay');
const overlayInputs = document.querySelectorAll('.overlay-radio');
const clearOverlayButton = document.querySelector('#clear-overlay');
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

let sourceMode = 'webcam';
let cameraStream = null;
let cameraRequestId = 0;
let uploadedImage = null;
let uploadedImageUrl = null;
let uploadLoadId = 0;
let captureBusy = false;
let photoUrl = null;

function updateTakePhotoButton() {
    const overlaySelected = document.querySelector('.overlay-radio:checked') !== null;
    const sourceReady = sourceMode === 'upload'
        ? uploadedImage !== null
        : cameraStream !== null && !stopButton.disabled;
    takePhotoButton.disabled = !sourceReady || !overlaySelected || captureBusy;
}

function stopCamera() {
    cameraRequestId++;
    if (cameraStream !== null) {
        cameraStream.getTracks().forEach((track) => track.stop());
        cameraStream = null;
    }

    video.pause();
    video.srcObject = null;
    startButton.disabled = false;
    stopButton.disabled = true;
    if (sourceMode === 'webcam') {
        statusMessage.textContent = 'Camera is off.';
    }
    updateTakePhotoButton();
}

function setSourceMode(mode) {
    sourceMode = mode;
    if (mode === 'upload') {
        stopCamera();
    }

    cameraButtons.hidden = mode !== 'webcam';
    uploadControls.hidden = mode !== 'upload';
    video.hidden = mode !== 'webcam';
    uploadPreview.hidden = mode !== 'upload' || uploadedImage === null;
    takePhotoButton.textContent = mode === 'webcam' ? 'Take photo' : 'Create photo';
    statusMessage.textContent = mode === 'upload'
        ? (uploadedImage === null ? 'Choose an image to preview.' : 'Using an uploaded image.')
        : (cameraStream === null ? 'Camera is off.' : 'Camera is on.');
    captureMessage.hidden = true;
    updateTakePhotoButton();
}

async function startCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        statusMessage.textContent = 'Camera access is unavailable in this browser or connection.';
        return;
    }

    const requestId = ++cameraRequestId;
    startButton.disabled = true;
    statusMessage.textContent = 'Requesting camera access…';
    let stream = null;

    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
        if (requestId !== cameraRequestId || sourceMode !== 'webcam') {
            stream.getTracks().forEach((track) => track.stop());
            return;
        }

        cameraStream = stream;
        video.srcObject = stream;
        await video.play();
        if (requestId !== cameraRequestId || sourceMode !== 'webcam') {
            if (cameraStream === stream) {
                stopCamera();
            }
            return;
        }

        stopButton.disabled = false;
        statusMessage.textContent = 'Camera is on.';
        updateTakePhotoButton();
    } catch (error) {
        if (requestId !== cameraRequestId || sourceMode !== 'webcam') {
            if (cameraStream === stream) {
                stopCamera();
            }
            return;
        }

        stopCamera();
        statusMessage.textContent = error && error.name === 'NotAllowedError'
            ? 'Camera permission was denied. Allow access in your browser and try again.'
            : 'The camera is unavailable. Check that it is connected and not in use.';
    }
}

function clearUploadedImage() {
    uploadLoadId++;
    uploadedImage = null;
    uploadPreview.hidden = true;
    uploadPreview.removeAttribute('src');
    if (uploadedImageUrl !== null) {
        URL.revokeObjectURL(uploadedImageUrl);
        uploadedImageUrl = null;
    }
    updateTakePhotoButton();
}

function showUploadError(message) {
    clearUploadedImage();
    uploadInput.value = '';
    statusMessage.textContent = 'Choose an image to preview.';
    captureMessage.textContent = message;
    captureMessage.hidden = false;
}

function handleUploadSelection() {
    const file = uploadInput.files[0];
    clearUploadedImage();
    captureMessage.hidden = true;
    statusMessage.textContent = 'Choose an image to preview.';

    if (!file) {
        return;
    }
    if (file.type !== 'image/png' && file.type !== 'image/jpeg') {
        showUploadError('Choose a PNG or JPEG image.');
        return;
    }
    if (file.size === 0 || file.size > 10 * 1024 * 1024) {
        showUploadError('Choose an image under 10 MB.');
        return;
    }

    const loadId = uploadLoadId;
    statusMessage.textContent = 'Loading image…';
    try {
        uploadedImageUrl = URL.createObjectURL(file);
    } catch {
        showUploadError('Could not read this image. Choose another file.');
        return;
    }

    const image = new Image();
    image.onload = () => {
        if (loadId !== uploadLoadId) {
            return;
        }
        if (image.naturalWidth === 0 || image.naturalHeight === 0
            || image.naturalWidth * image.naturalHeight > 24 * 1000 * 1000) {
            showUploadError('Choose an image of 24 megapixels or less.');
            return;
        }

        uploadedImage = image;
        uploadPreview.src = uploadedImageUrl;
        uploadPreview.hidden = sourceMode !== 'upload';
        if (sourceMode === 'upload') {
            statusMessage.textContent = 'Using an uploaded image.';
        }
        updateTakePhotoButton();
    };
    image.onerror = () => {
        if (loadId === uploadLoadId) {
            showUploadError('Could not read this image. Choose another file.');
        }
    };
    image.src = uploadedImageUrl;
}

webcamOption.addEventListener('change', () => setSourceMode('webcam'));
uploadOption.addEventListener('change', () => setSourceMode('upload'));
uploadInput.addEventListener('change', handleUploadSelection);
startButton.addEventListener('click', startCamera);
stopButton.addEventListener('click', stopCamera);

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
    const isUpload = sourceMode === 'upload';
    const source = isUpload ? uploadedImage : video;
    if (selectedOverlay === null || captureBusy
        || (isUpload ? source === null : cameraStream === null || stopButton.disabled)) {
        return;
    }

    const overlayName = selectedOverlay.value;
    captureBusy = true;
    updateTakePhotoButton();
    captureMessage.hidden = true;
    captureSuccess.hidden = true;

    try {
        const sourceWidth = isUpload ? source.naturalWidth : video.videoWidth;
        const sourceHeight = isUpload ? source.naturalHeight : video.videoHeight;
        if (sourceWidth === 0 || sourceHeight === 0) {
            throw new Error('Image frame unavailable.');
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
            source,
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
        formData.append('overlay', overlayName);
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
        finalPhoto.alt = `Photo with ${overlayName} overlay`;
        captureResult.hidden = false;
        captureSuccess.hidden = false;
    } catch {
        captureMessage.textContent = 'Could not create or save the photo. Please try again.';
        captureMessage.hidden = false;
    } finally {
        captureBusy = false;
        updateTakePhotoButton();
    }
}

takePhotoButton.addEventListener('click', takePhoto);
window.addEventListener('pagehide', () => {
    stopCamera();
    clearUploadedImage();
    if (photoUrl !== null) {
        URL.revokeObjectURL(photoUrl);
    }
});
