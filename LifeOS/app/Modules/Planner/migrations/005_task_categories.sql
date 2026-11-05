ALTER TABLE tasks ADD COLUMN category TEXT NOT NULL DEFAULT 'task' CHECK(category IN ('task','exam','todo'));

UPDATE tasks SET category='todo' WHERE source='desktop-panel-todo';

-- Seed only clear legacy exam names once. Future grouping reads category, not title.
UPDATE tasks SET category='exam'
WHERE category='task' AND (
    lower(' ' || trim(title) || ' ') LIKE '% exam %'
    OR lower(' ' || trim(title) || ' ') LIKE '% examination %'
);
