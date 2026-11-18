const photoButtons = [...document.querySelectorAll('.photo-open')];
const lightbox = document.querySelector('#photo-lightbox');
const lightboxImage = document.querySelector('#lightbox-image');
const lightboxAuthor = document.querySelector('#lightbox-author');
const lightboxCounter = document.querySelector('#lightbox-counter');
const previousButton = document.querySelector('#lightbox-prev');
const nextButton = document.querySelector('#lightbox-next');
const closeButton = document.querySelector('#lightbox-close');

let currentIndex = 0;
let openingButton = null;

function showPhoto(index) {
    currentIndex = index;
    const button = photoButtons[index];
    const author = button.dataset.author;

    lightboxImage.src = button.querySelector('img').getAttribute('src');
    lightboxImage.alt = `Photo shared by ${author}`;
    lightboxAuthor.textContent = `By ${author}`;
    lightboxCounter.textContent = `${index + 1} / ${photoButtons.length}`;
}

function movePhoto(step) {
    showPhoto((currentIndex + step + photoButtons.length) % photoButtons.length);
}

photoButtons.forEach((button, index) => {
    button.addEventListener('click', () => {
        openingButton = button;
        showPhoto(index);
        lightbox.showModal();
    });
});

previousButton.hidden = photoButtons.length < 2;
nextButton.hidden = photoButtons.length < 2;
previousButton.addEventListener('click', () => movePhoto(-1));
nextButton.addEventListener('click', () => movePhoto(1));
closeButton.addEventListener('click', () => lightbox.close());

lightbox.addEventListener('click', (event) => {
    if (event.target === lightbox) {
        lightbox.close();
    }
});

lightbox.addEventListener('close', () => {
    openingButton?.focus();
});

document.addEventListener('keydown', (event) => {
    if (!lightbox.open || photoButtons.length < 2) {
        return;
    }

    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault();
        movePhoto(event.key === 'ArrowLeft' ? -1 : 1);
    }
});
