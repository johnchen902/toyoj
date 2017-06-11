CREATE TABLE checkers (
    name VARCHAR(32) PRIMARY KEY
);
CREATE TABLE languages (
    name VARCHAR(32) PRIMARY KEY
);
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(32) UNIQUE NOT NULL,
    register_time TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now()
);
CREATE TABLE user_permissions (
    user_id INTEGER NOT NULL REFERENCES users ON DELETE CASCADE,
    permission_name VARCHAR(32) NOT NULL,
    PRIMARY KEY (user_id, permission_name)
);
CREATE TABLE password_logins (
    user_id INTEGER PRIMARY KEY REFERENCES users ON DELETE CASCADE,
    password_hash VARCHAR(256) NOT NULL
);
CREATE TABLE problems (
    id SERIAL PRIMARY KEY,
    title VARCHAR(128) NOT NULL,
    statement TEXT NOT NULL,
    ready BOOLEAN NOT NULL,
    manager_id INTEGER NOT NULL REFERENCES users,
    create_time TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now()
);
CREATE TABLE subtasks (
    id SERIAL PRIMARY KEY,
    problem_id INTEGER NOT NULL REFERENCES problems ON DELETE CASCADE,
    score INTEGER NOT NULL CHECK (score > 0),
    UNIQUE (id, problem_id)
);
CREATE TABLE testcases (
    id SERIAL PRIMARY KEY,
    problem_id INTEGER NOT NULL REFERENCES problems ON DELETE CASCADE,
    time_limit INTEGER NOT NULL CHECK (time_limit > 0),
    memory_limit INTEGER NOT NULL CHECK (memory_limit > 0),
    checker_name VARCHAR(32) NOT NULL REFERENCES checkers,
    input TEXT NOT NULL,
    output TEXT NOT NULL,
    UNIQUE (id, problem_id)
);
CREATE TABLE subtask_testcases (
    subtask_id INTEGER NOT NULL REFERENCES subtasks ON DELETE CASCADE,
    testcase_id INTEGER NOT NULL REFERENCES testcases ON DELETE CASCADE,
    problem_id INTEGER NOT NULL REFERENCES problems ON DELETE CASCADE,
    PRIMARY KEY (subtask_id, testcase_id),
    FOREIGN KEY (subtask_id, problem_id) REFERENCES subtasks (id, problem_id) ON DELETE CASCADE,
    FOREIGN KEY (testcase_id, problem_id) REFERENCES testcases (id, problem_id) ON DELETE CASCADE
);
CREATE TABLE submissions (
    id SERIAL PRIMARY KEY,
    problem_id INTEGER NOT NULL REFERENCES problems,
    submitter_id INTEGER NOT NULL REFERENCES users,
    language_name VARCHAR(32) NOT NULL REFERENCES languages,
    code TEXT NOT NULL,
    submit_time TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
    UNIQUE (id, problem_id)
);
CREATE TABLE result_judges (
    submission_id INTEGER NOT NULL REFERENCES submissions ON DELETE CASCADE,
    testcase_id INTEGER NOT NULL REFERENCES testcases ON DELETE CASCADE,
    problem_id INTEGER NOT NULL REFERENCES problems ON DELETE CASCADE,
    judge_name VARCHAR(32) NOT NULL,
    PRIMARY KEY (submission_id, testcase_id),
    FOREIGN KEY (submission_id, problem_id) REFERENCES submissions (id, problem_id) ON DELETE CASCADE,
    FOREIGN KEY (testcase_id, problem_id) REFERENCES testcases (id, problem_id) ON DELETE CASCADE
);
CREATE TABLE results (
    submission_id INTEGER NOT NULL REFERENCES submissions ON DELETE CASCADE,
    testcase_id INTEGER NOT NULL REFERENCES testcases ON DELETE CASCADE,
    problem_id INTEGER NOT NULL REFERENCES problems ON DELETE CASCADE,
    accepted BOOLEAN NOT NULL,
    time INTEGER NOT NULL CHECK (time >= 0),
    memory INTEGER NOT NULL CHECK (memory >= 0),
    verdict VARCHAR(3) NOT NULL,
    judge_time TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
    PRIMARY KEY (submission_id, testcase_id),
    FOREIGN KEY (submission_id, problem_id) REFERENCES submissions (id, problem_id) ON DELETE CASCADE,
    FOREIGN KEY (testcase_id, problem_id) REFERENCES testcases (id, problem_id) ON DELETE CASCADE
);

CREATE VIEW subtask_testcases_view AS SELECT
    k.problem_id,
    k.id AS subtask_id,
    t.id AS testcase_id,
    (EXISTS (SELECT 1 FROM subtask_testcases WHERE subtask_id = k.id AND testcase_id = t.id)) AS "exists"
FROM subtasks k JOIN testcases t USING (problem_id);

CREATE FUNCTION subtask_testcases_view_update() RETURNS trigger AS $$
BEGIN
    IF OLD.problem_id != NEW.problem_id THEN
        RAISE EXCEPTION 'problem_id cannot be modified';
    END IF;
    IF OLD.subtask_id != NEW.subtask_id THEN
        RAISE EXCEPTION 'subtask_id cannot be modified';
    END IF;
    IF OLD.testcase_id != NEW.testcase_id THEN
        RAISE EXCEPTION 'testcase_id cannot be modified';
    END IF;
    IF OLD."exists" != NEW."exists" THEN
        IF NEW."exists" THEN
            INSERT INTO subtask_testcases (problem_id, subtask_id, testcase_id)
                VALUES (NEW.problem_id, NEW.subtask_id, NEW.testcase_id);
        ELSE
            DELETE FROM subtask_testcases
                WHERE problem_id = NEW.problem_id AND
                    subtask_id = NEW.subtask_id AND
                    testcase_id = NEW.testcase_id;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER subtask_testcases_view_update_trigger
    INSTEAD OF UPDATE ON subtask_testcases_view
    FOR EACH ROW EXECUTE PROCEDURE subtask_testcases_view_update();

CREATE VIEW results_view AS SELECT
    s.id AS submission_id,
    t.id AS testcase_id,
    r.accepted,
    r.verdict,
    r.time,
    r.memory,
    j.judge_name,
    r.judge_time
FROM submissions s JOIN testcases t USING (problem_id)
    LEFT JOIN result_judges j ON (s.id = j.submission_id AND t.id = j.testcase_id)
    LEFT JOIN results r ON (s.id = r.submission_id AND t.id = r.testcase_id);

CREATE VIEW subtask_results_view AS WITH subtask_results_view_0 AS (SELECT
    s.id AS submission_id,
    k.id AS subtask_id,
    bool_and(r.accepted IS TRUE) AS accepted,
    bool_or(r.accepted IS FALSE) AS rejected,
    max(r.time) AS time,
    max(r.memory) AS memory,
    k.score AS fullscore,
    max(r.judge_time) AS judge_time
FROM submissions s JOIN subtasks k USING (problem_id)
    JOIN subtask_testcases kt ON (k.id = kt.subtask_id)
    LEFT JOIN results r ON (s.id = r.submission_id AND kt.testcase_id = r.testcase_id)
GROUP BY s.id, k.id)
SELECT
    submission_id,
    subtask_id,
    accepted,
    rejected,
    time,
    memory,
    CASE WHEN accepted THEN fullscore ELSE 0 END AS minscore,
    CASE WHEN rejected THEN 0 ELSE fullscore END AS maxscore,
    fullscore,
    judge_time
FROM subtask_results_view_0;

CREATE VIEW submission_results_view AS SELECT
    s.id AS submission_id,
    x.accepted,
    x.rejected,
    x.time,
    x.memory,
    x.judge_time,
    y.minscore,
    y.maxscore,
    y.fullscore
FROM submissions s
    LEFT JOIN (
        SELECT
            r.submission_id,
            bool_and(r.accepted IS TRUE) AS accepted,
            bool_or(r.accepted IS FALSE) AS rejected,
            max(r.time) AS time,
            max(r.memory) AS memory,
            max(r.judge_time) AS judge_time
        FROM results_view r
        GROUP BY r.submission_id
    ) x ON (s.id = x.submission_id)
    LEFT JOIN (
        SELECT
            k.submission_id,
            sum(k.minscore) AS minscore,
            sum(k.maxscore) AS maxscore,
            sum(k.fullscore) AS fullscore
        FROM subtask_results_view k
        GROUP BY k.submission_id
    ) y ON (s.id = y.submission_id);

GRANT SELECT ON checkers TO toyojweb;
GRANT SELECT ON languages TO toyojweb;
GRANT SELECT, INSERT ON users TO toyojweb;
GRANT SELECT ON user_permissions TO toyojweb;
GRANT SELECT, INSERT, UPDATE ON password_logins TO toyojweb;
GRANT SELECT, INSERT, UPDATE ON problems TO toyojweb;
GRANT SELECT, INSERT, DELETE, UPDATE ON subtasks TO toyojweb;
GRANT SELECT, INSERT, DELETE, UPDATE ON testcases TO toyojweb;
GRANT SELECT, INSERT, DELETE ON subtask_testcases TO toyojweb;
GRANT SELECT, INSERT ON submissions TO toyojweb;
GRANT SELECT ON result_judges TO toyojweb;
GRANT SELECT ON results TO toyojweb;
GRANT SELECT, UPDATE ON subtask_testcases_view TO toyojweb;
GRANT SELECT ON results_view TO toyojweb;
GRANT SELECT ON subtask_results_view TO toyojweb;
GRANT SELECT ON submission_results_view TO toyojweb;
GRANT USAGE ON users_id_seq TO toyojweb;
GRANT USAGE ON problems_id_seq TO toyojweb;
GRANT USAGE ON subtasks_id_seq TO toyojweb;
GRANT USAGE ON testcases_id_seq TO toyojweb;
GRANT USAGE ON submissions_id_seq TO toyojweb;
