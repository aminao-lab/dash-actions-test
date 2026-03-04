-- =============================================================================
-- SCHEMA DASHBOARD D-PHI ALPHA
-- Tables avec user_id (VARCHAR) comme clé primaire directe
-- Compatible avec les scripts de sync PHP
-- =============================================================================

-- Nettoyage complet (à décommenter si réinitialisation)
-- DROP VIEW IF EXISTS view_monthly_regularity CASCADE;
-- DROP VIEW IF EXISTS students_with_progress CASCADE;
-- DROP TABLE IF EXISTS daily_activity CASCADE;
-- DROP TABLE IF EXISTS daily_cumul_snapshot CASCADE;
-- DROP TABLE IF EXISTS temps_week CASCADE;
-- DROP TABLE IF EXISTS temps_niveau CASCADE;
-- DROP TABLE IF EXISTS progression CASCADE;
-- DROP TABLE IF EXISTS students CASCADE;

-- =============================================================================
-- TABLE 1 : STUDENTS
-- =============================================================================
CREATE TABLE IF NOT EXISTS students (
    user_id      VARCHAR(100) PRIMARY KEY,
    email        VARCHAR(255),
    username     VARCHAR(255),
    tags         TEXT,
    created_at   TIMESTAMP WITH TIME ZONE,
    last_login_at TIMESTAMP WITH TIME ZONE,
    is_enrolled  BOOLEAN DEFAULT true,
    date_maj     TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_students_email    ON students (email);
CREATE INDEX IF NOT EXISTS idx_students_enrolled ON students (is_enrolled);
CREATE INDEX IF NOT EXISTS idx_students_date_maj ON students (date_maj);

COMMENT ON TABLE  students             IS 'Utilisateurs enrolled synchronisés depuis LearnWorlds';
COMMENT ON COLUMN students.user_id     IS 'ID unique LearnWorlds (clé primaire)';
COMMENT ON COLUMN students.is_enrolled IS 'True si inscrit à au moins un cours';

-- =============================================================================
-- TABLE 2 : PROGRESSION (% d'avancement par niveau)
-- =============================================================================
CREATE TABLE IF NOT EXISTS progression (
    user_id    VARCHAR(100) PRIMARY KEY REFERENCES students(user_id) ON DELETE CASCADE,
    "6eme"     DECIMAL(5,2) DEFAULT 0.00,
    "5eme"     DECIMAL(5,2) DEFAULT 0.00,
    "4eme"     DECIMAL(5,2) DEFAULT 0.00,
    "3eme"     DECIMAL(5,2) DEFAULT 0.00,
    "2nde"     DECIMAL(5,2) DEFAULT 0.00,
    "1ere"     DECIMAL(5,2) DEFAULT 0.00,
    term       DECIMAL(5,2) DEFAULT 0.00,
    "term-pc"  DECIMAL(5,2) DEFAULT 0.00,
    date_maj   TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_progression_date_maj ON progression (date_maj);

COMMENT ON TABLE progression IS 'Score d''avancement par niveau (0-100%)';

-- =============================================================================
-- TABLE 3 : TEMPS_NIVEAU (temps total par niveau, en secondes)
-- =============================================================================
CREATE TABLE IF NOT EXISTS temps_niveau (
    user_id   VARCHAR(100) PRIMARY KEY REFERENCES students(user_id) ON DELETE CASCADE,
    "6eme"    INTEGER DEFAULT 0,
    "5eme"    INTEGER DEFAULT 0,
    "4eme"    INTEGER DEFAULT 0,
    "3eme"    INTEGER DEFAULT 0,
    "2nde"    INTEGER DEFAULT 0,
    "1ere"    INTEGER DEFAULT 0,
    term      INTEGER DEFAULT 0,
    "term-pc" INTEGER DEFAULT 0,
    date_maj  TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_temps_niveau_date_maj ON temps_niveau (date_maj);

COMMENT ON TABLE  temps_niveau         IS 'Temps total d''apprentissage cumulé par niveau (secondes)';
COMMENT ON COLUMN temps_niveau."6eme"  IS 'Temps en secondes pour le niveau 6ème';

-- =============================================================================
-- TABLE 4 : TEMPS_WEEK (temps hebdomadaire par niveau)
-- =============================================================================
CREATE TABLE IF NOT EXISTS temps_week (
    user_id   VARCHAR(100) NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
    semaine   VARCHAR(10) NOT NULL,          -- Format ISO : "2025-W01"
    "6eme"    INTEGER DEFAULT 0,
    "5eme"    INTEGER DEFAULT 0,
    "4eme"    INTEGER DEFAULT 0,
    "3eme"    INTEGER DEFAULT 0,
    "2nde"    INTEGER DEFAULT 0,
    "1ere"    INTEGER DEFAULT 0,
    term      INTEGER DEFAULT 0,
    "term-pc" INTEGER DEFAULT 0,
    debute_le DATE,                           -- Lundi de la semaine
    finit_le  DATE,                           -- Dimanche de la semaine
    date_maj  TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    PRIMARY KEY (user_id, semaine)
);

CREATE INDEX IF NOT EXISTS idx_temps_week_semaine         ON temps_week (semaine);
CREATE INDEX IF NOT EXISTS idx_temps_week_user_semaine    ON temps_week (user_id, semaine);
CREATE INDEX IF NOT EXISTS idx_temps_week_date_maj        ON temps_week (date_maj);

COMMENT ON TABLE  temps_week          IS 'Temps hebdomadaire par niveau';
COMMENT ON COLUMN temps_week.semaine  IS 'Semaine ISO (ex: 2025-W01)';
COMMENT ON COLUMN temps_week."6eme"   IS 'Temps cette semaine en secondes pour 6ème';

-- =============================================================================
-- TABLE 5 : DAILY_ACTIVITY (activité quotidienne, 1 ligne par user par jour)
-- =============================================================================
CREATE TABLE IF NOT EXISTS daily_activity (
    user_id       VARCHAR(100) NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
    activity_date DATE         NOT NULL,
    seconds_spent INTEGER      DEFAULT 0,
    PRIMARY KEY (user_id, activity_date)
);

CREATE INDEX IF NOT EXISTS idx_daily_activity_date    ON daily_activity (activity_date);
CREATE INDEX IF NOT EXISTS idx_daily_activity_user    ON daily_activity (user_id);

COMMENT ON TABLE  daily_activity               IS 'Jours d''activité par utilisateur (delta > seuil)';
COMMENT ON COLUMN daily_activity.seconds_spent IS 'Secondes passées ce jour (delta cumul)';

-- =============================================================================
-- TABLE 6 : DAILY_CUMUL_SNAPSHOT (snapshot journalier du cumul total)
-- =============================================================================
CREATE TABLE IF NOT EXISTS daily_cumul_snapshot (
    user_id             VARCHAR(100) NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
    snapshot_date       DATE         NOT NULL,
    total_cumul_seconds INTEGER      DEFAULT 0,
    streak_jours        INTEGER      DEFAULT 0,
    streak_mois_pct     INTEGER      DEFAULT 0,
    PRIMARY KEY (user_id, snapshot_date)
);

CREATE INDEX IF NOT EXISTS idx_snapshot_date ON daily_cumul_snapshot (snapshot_date);

COMMENT ON TABLE  daily_cumul_snapshot                    IS 'Snapshot journalier du cumul total par user';
COMMENT ON COLUMN daily_cumul_snapshot.total_cumul_seconds IS 'Somme de tous les temps_niveau à ce jour';
COMMENT ON COLUMN daily_cumul_snapshot.streak_jours        IS 'Nb jours actifs ce mois';

-- =============================================================================
-- VUE 1 : students_with_progress
-- Jointure students + progression + temps_niveau
-- Utilisée par /api/me.php
-- =============================================================================
CREATE OR REPLACE VIEW students_with_progress AS
SELECT
    s.user_id,
    s.email,
    s.username,
    s.tags,
    s.created_at,
    s.last_login_at,
    s.date_maj,
    -- Progression (%)
    COALESCE(p."6eme",    0) AS prog_6eme,
    COALESCE(p."5eme",    0) AS prog_5eme,
    COALESCE(p."4eme",    0) AS prog_4eme,
    COALESCE(p."3eme",    0) AS prog_3eme,
    COALESCE(p."2nde",    0) AS prog_2nde,
    COALESCE(p."1ere",    0) AS prog_1ere,
    COALESCE(p.term,      0) AS prog_term,
    COALESCE(p."term-pc", 0) AS prog_term_pc,
    -- Temps (secondes)
    COALESCE(tn."6eme",    0) AS temps_6eme,
    COALESCE(tn."5eme",    0) AS temps_5eme,
    COALESCE(tn."4eme",    0) AS temps_4eme,
    COALESCE(tn."3eme",    0) AS temps_3eme,
    COALESCE(tn."2nde",    0) AS temps_2nde,
    COALESCE(tn."1ere",    0) AS temps_1ere,
    COALESCE(tn.term,      0) AS temps_term,
    COALESCE(tn."term-pc", 0) AS temps_term_pc
FROM students s
LEFT JOIN progression  p  ON s.user_id = p.user_id
LEFT JOIN temps_niveau tn ON s.user_id = tn.user_id;

COMMENT ON VIEW students_with_progress IS 'Vue complète : élèves + progression + temps total';

-- =============================================================================
-- VUE 2 : view_monthly_regularity
-- Compte les jours actifs par user par mois
-- Utilisée par /api/regularity.php
-- =============================================================================
CREATE OR REPLACE VIEW view_monthly_regularity AS
SELECT
    user_id,
    date_trunc('month', activity_date)::date AS month_start,
    COUNT(DISTINCT activity_date)::integer   AS active_days
FROM daily_activity
GROUP BY user_id, date_trunc('month', activity_date)::date;

COMMENT ON VIEW view_monthly_regularity IS 'Nb de jours actifs par user et par mois';

-- =============================================================================
-- TRIGGER : mise à jour automatique de date_maj
-- =============================================================================
CREATE OR REPLACE FUNCTION update_date_maj()
RETURNS TRIGGER AS $$
BEGIN
    NEW.date_maj = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS students_date_maj   ON students;
DROP TRIGGER IF EXISTS progression_date_maj ON progression;
DROP TRIGGER IF EXISTS temps_niveau_date_maj ON temps_niveau;
DROP TRIGGER IF EXISTS temps_week_date_maj  ON temps_week;

CREATE TRIGGER students_date_maj
    BEFORE UPDATE ON students
    FOR EACH ROW EXECUTE FUNCTION update_date_maj();

CREATE TRIGGER progression_date_maj
    BEFORE UPDATE ON progression
    FOR EACH ROW EXECUTE FUNCTION update_date_maj();

CREATE TRIGGER temps_niveau_date_maj
    BEFORE UPDATE ON temps_niveau
    FOR EACH ROW EXECUTE FUNCTION update_date_maj();

CREATE TRIGGER temps_week_date_maj
    BEFORE UPDATE ON temps_week
    FOR EACH ROW EXECUTE FUNCTION update_date_maj();

-- =============================================================================
-- ROW LEVEL SECURITY (RLS) — À activer pour sécuriser l'accès
-- Chaque user ne voit que SES données via la clé anon
-- La service_key (backend PHP) bypasse le RLS
-- =============================================================================

-- ALTER TABLE students           ENABLE ROW LEVEL SECURITY;
-- ALTER TABLE progression        ENABLE ROW LEVEL SECURITY;
-- ALTER TABLE temps_niveau       ENABLE ROW LEVEL SECURITY;
-- ALTER TABLE temps_week         ENABLE ROW LEVEL SECURITY;
-- ALTER TABLE daily_activity     ENABLE ROW LEVEL SECURITY;
-- ALTER TABLE daily_cumul_snapshot ENABLE ROW LEVEL SECURITY;

-- Exemple de politique (décommenter et adapter si tu utilises la clé anon côté client) :
-- CREATE POLICY "Users see own data" ON students
--     FOR SELECT USING (user_id = current_setting('app.user_id', true));

-- =============================================================================
-- FONCTION UTILITAIRE : secondes → format lisible
-- =============================================================================
CREATE OR REPLACE FUNCTION seconds_to_human(seconds INTEGER)
RETURNS VARCHAR AS $$
BEGIN
    IF seconds IS NULL OR seconds = 0 THEN RETURN '0 sec'; END IF;
    IF seconds < 60   THEN RETURN seconds || ' sec'; END IF;
    IF seconds < 3600 THEN RETURN FLOOR(seconds / 60) || ' min'; END IF;
    RETURN FLOOR(seconds / 3600) || 'h' || LPAD(FLOOR((seconds % 3600) / 60)::TEXT, 2, '0');
END;
$$ LANGUAGE plpgsql IMMUTABLE;
