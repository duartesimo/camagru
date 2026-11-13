'use strict';

const video = document.querySelector('#camera-preview');
const startButton = document.querySelector('#start-camera');
const stopButton = document.querySelector('#stop-camera');
const statusMessage = document.querySelector('#camera-status');

let cameraStream = null;

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
            selectedOverlayImage.src = input.value;
            selectedOverlayImage.hidden = false;
            clearOverlayButton.disabled = false;
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
});
