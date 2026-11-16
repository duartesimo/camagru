CREATE TABLE comments (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    photo_id BIGINT NOT NULL REFERENCES photos(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    content TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT comments_content_valid CHECK (
        char_length(content) BETWEEN 1 AND 500 AND content ~ '[^[:space:]]'
    )
);

CREATE INDEX comments_photo_order_idx ON comments (photo_id, created_at, id);
