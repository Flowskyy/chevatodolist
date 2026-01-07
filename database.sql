-- Buat tabel tasks jika belum ada
CREATE TABLE IF NOT EXISTS tasks (
    id SERIAL PRIMARY KEY,
    task_name VARCHAR(255) NOT NULL,
    list_order INTEGER NOT NULL DEFAULT 0,
    is_completed BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Index untuk performa
CREATE INDEX IF NOT EXISTS idx_list_order ON tasks(list_order);
CREATE INDEX IF NOT EXISTS idx_is_completed ON tasks(is_completed);

-- Data awal (optional)
INSERT INTO tasks (task_name, list_order, is_completed) VALUES
('Selamat datang di Cheva''s To Do List!', 1, false),
('Login sebagai admin untuk mengedit list', 2, false),
('Password: admin123', 3, false)
ON CONFLICT DO NOTHING;

-- Trigger untuk auto update updated_at
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

DROP TRIGGER IF EXISTS update_tasks_updated_at ON tasks;
CREATE TRIGGER update_tasks_updated_at 
    BEFORE UPDATE ON tasks 
    FOR EACH ROW 
    EXECUTE FUNCTION update_updated_at_column();