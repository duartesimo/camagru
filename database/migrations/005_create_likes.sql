CREATE TABLE likes (
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    photo_id BIGINT NOT NULL REFERENCES photos(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, photo_id)
);

CREATE INDEX likes_photo_id_idx ON likes (photo_id);
