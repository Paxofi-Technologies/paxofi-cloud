-- Fixture only: proves multi-statement files and comments work end to end.
CREATE TABLE example_accounts (
    id CHAR(26) NOT NULL PRIMARY KEY,
    display_name VARCHAR(190) NOT NULL,
    note VARCHAR(190) NOT NULL DEFAULT 'semi;colon'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO example_accounts (id, display_name) VALUES ('01J9ZZZZZZZZZZZZZZZZZZZZZZ', 'Fixture; Account');
