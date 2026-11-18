# Camagru

A photo-sharing web application built for the **42 Camagru project**. Users can take a photo with their webcam or upload an image, apply a transparent PNG overlay, and share the result in a public gallery where registered users can like and comment on photos.

The application uses native PHP, HTML, CSS and JavaScript, without application frameworks or third-party PHP/JavaScript libraries.

## Features

### Accounts

- Register with a username, email address and password.
- Confirm a new account using a unique email verification link before logging in.
- Log in, log out and reset a forgotten password by email.
- Change username, email and password in account settings.
- Verify a new email address after changing it.
- Enable or disable comment notification emails (enabled by default).

### Photo editor

- Capture an image from a webcam or upload a PNG/JPEG image.
- Select from transparent PNG overlays and preview the effect before capture.
- Capture is disabled until an overlay is selected.
- Compose the final image **on the server** using PHP GD.
- Save finished photos as 640 × 480 PNG images (4:3 format).
- Browse previously created photos in the editor and delete your own photos.

### Public gallery

- View everyone's photos without logging in, with the newest photos first.
- Browse the gallery in pages of six photos.
- See like counts and read comments as a guest.
- Like or unlike photos and post comments when logged in.
- Receive an email when one of your photos gets a comment, unless notifications are disabled.

## Technology

| Component | Technology |
| --- | --- |
| Server | PHP 8.4, Apache |
| Database | PostgreSQL 17, accessed with PDO |
| Image processing | PHP GD |
| Frontend | HTML, CSS, vanilla JavaScript and browser APIs |
| Email delivery | PHP `mail()` with `msmtp` |
| Development email inbox | Mailpit |
| Deployment | Docker Compose and Make |

No Composer, npm or external application framework is required.

## Requirements

- Docker with Docker Compose (`docker compose`)
- Make
- A modern version of Chrome or Firefox
- A webcam, if you want to test camera capture (image upload is also supported)

Run the commands below from the **project root**, where `Makefile` and `docker-compose.yml` are located.

## Installation

**1. Create the local environment file:**

```bash
cp .env.example .env
```

**2. Open `.env` and set your own PostgreSQL password.** For a local installation, the configuration looks like this:

```dotenv
POSTGRES_DB=camagru
POSTGRES_USER=camagru
POSTGRES_PASSWORD=choose-a-unique-local-password
APP_URL=http://localhost:8080
MAIL_FROM=no-reply@camagru.test
```

**3. Build and start the application:**

```bash
make up
```

This starts the PHP/Apache application, PostgreSQL and Mailpit. On a **fresh database volume**, PostgreSQL automatically executes the six SQL files in `database/migrations/` in filename order. No manual SQL commands are needed for a new installation.

**4. Open the application:**

| Service | Local address |
| --- | --- |
| Camagru | http://localhost:8080 |
| Mailpit inbox | http://localhost:8025 |

The application and Mailpit web interface are configured for local access.

## First use

1. Open **Camagru** and choose **Register**.
2. Register a user with a valid email address, username and password.
3. Open **Mailpit** at `http://localhost:8025` and locate the verification email. Mailpit captures all application emails in one local inbox, regardless of the recipient address.
4. Open the verification link, then log in to Camagru.
5. Go to **Editor**, choose an overlay, and either start your webcam or upload an image. Allow camera access if your browser asks for permission.
6. Take a photo. It is saved to your account and becomes visible in the public gallery.
7. Create a second account to test likes, comments and notification emails.

The same Mailpit inbox is used for registration verification, password resets and comment notifications. **Mailpit does not deliver messages to real Gmail/Outlook inboxes**; it is intended for local development and evaluation.

## Running and stopping

```bash
make up       # Build/start the services
make down     # Stop the services without removing named volumes
make restart  # Restart the services
```

Check the container status with:

```bash
docker compose ps
```

### Database and photo persistence

- PostgreSQL data is stored in the persistent `postgres_data` Docker volume.
- Uploaded/created photos are stored in a separate persistent photo volume, outside Apache's public document root.
- Photo metadata, users, likes, comments and account tokens are stored in PostgreSQL.
- Running `make down` or starting the containers again does not normally delete these volumes.

## Project structure

```text
camagru/
├── database/
│   └── migrations/         # SQL schema initialization (001–006)
├── docker/
│   └── php/
│       └── Dockerfile      # PHP, Apache, GD, PostgreSQL and email transport
├── public/                 # Apache document root
│   ├── assets/
│   │   ├── css/            # Application styles
│   │   ├── images/overlays/ # Transparent PNG overlays
│   │   └── js/             # Photo editor JavaScript
│   └── *.php               # Pages and HTTP endpoints
├── src/                    # Internal PHP helpers (not directly web-accessible)
│   ├── auth.php            # Sessions and authentication checks
│   ├── database.php        # PDO connection
│   └── email.php           # Email helpers
├── .env.example            # Environment variable template
├── docker-compose.yml
└── Makefile
```

Photos created by users are not placed in `public/assets/images/`. They are stored outside the document root and served through `public/photo.php`.

## Security notes

The application includes server-side input validation, password hashing, prepared SQL statements, HTML escaping, CSRF protection for state-changing requests, authentication checks and ownership validation for photo deletion. Email verification and password reset links use time-limited, single-use tokens, with token hashes stored in the database.

## Manual testing

The project can be checked manually using the browser and Mailpit:

1. Register and verify an account; confirm that login is blocked until verification.
2. Request a password reset and confirm the old password no longer works.
3. Change account settings, including email verification and comment notification preferences.
4. Capture a webcam photo and upload a PNG/JPEG photo with different overlays.
5. Confirm that capture is disabled without an overlay and that saved images appear in the gallery.
6. Log in with another account to like/unlike photos and add comments; confirm guests can view but cannot interact.
7. Confirm comment emails appear in Mailpit only when the photo owner's preference is enabled.
8. Delete your own photo, check that it disappears, and confirm you cannot delete another user's photo.
9. Check gallery pagination, mobile layout, and both Chrome and Firefox.
10. Restart the containers and confirm that users, photos and comments are preserved.

## 42 subject

This project implements the mandatory Camagru features: account management and verification, a public paginated gallery with likes and comments, comment email notifications with opt-out, webcam capture and image upload, transparent overlays, server-side image composition, owner-only photo deletion, a responsive interface and one-command containerized startup.
