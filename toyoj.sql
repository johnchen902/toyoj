-- schema

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
    manager_id INTEGER NOT NULL REFERENCES users,
    create_time TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now()
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
    time INTEGER NULL CHECK (time >= 0), -- should be null if the program is not ran at all (e.g. CE)
    memory INTEGER NULL CHECK (memory >= 0), -- same as time
    verdict VARCHAR(3) NOT NULL,
    judge_time TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
    PRIMARY KEY (submission_id, testcase_id),
    FOREIGN KEY (submission_id, problem_id) REFERENCES submissions (id, problem_id) ON DELETE CASCADE,
    FOREIGN KEY (testcase_id, problem_id) REFERENCES testcases (id, problem_id) ON DELETE CASCADE
);

CREATE VIEW problems_view AS
SELECT p.id,
       p.title,
       p.statement,
       p.manager_id,
       p.create_time,
       u.username AS manager_username
FROM problems p
INNER JOIN users u ON (p.manager_id = u.id);

CREATE VIEW results_view AS SELECT
    s.id AS submission_id,
    t.id AS testcase_id,
    s.problem_id AS problem_id,
    r.accepted,
    r.verdict,
    r.time,
    r.memory,
    j.judge_name,
    r.judge_time,
    s.language_name as language_name, -- judge want this
    t.checker_name as checker_name -- same as language_name
FROM submissions s JOIN testcases t USING (problem_id)
    LEFT JOIN result_judges j ON (s.id = j.submission_id AND t.id = j.testcase_id)
    LEFT JOIN results r ON (s.id = r.submission_id AND t.id = r.testcase_id);

CREATE VIEW submissions_view AS
SELECT s.id,
       s.problem_id,
       s.submitter_id,
       s.language_name,
       s.code,
       s.submit_time,
       p.title as problem_title,
       u.username as submitter_username,
       t.count AS testcases_count,
       r.count AS results_count,
       r.time,
       r.memory,
       r.judge_time,
       rr.testcase_id AS verdict_id,
       rr.verdict AS verdict
FROM submissions AS s
INNER JOIN problems AS p ON (s.problem_id = p.id)
INNER JOIN users AS u ON (s.submitter_id = u.id)
LEFT JOIN (
    SELECT problem_id,
           COUNT(*) AS count
    FROM testcases
    GROUP BY problem_id
) AS t USING (problem_id)
LEFT JOIN (
    SELECT submission_id,
           COUNT(*) AS count,
           max(time) AS time,
           max(memory) AS memory,
           max(judge_time) AS judge_time
    FROM results
    GROUP BY submission_id
) AS r ON (s.id = r.submission_id)
LEFT JOIN (
    SELECT DISTINCT ON (submission_id)
           submission_id,
           testcase_id,
           verdict
    FROM results
    WHERE NOT accepted
) AS rr ON (s.id = rr.submission_id);

CREATE FUNCTION notify_new_judge_task() RETURNS trigger AS $$
BEGIN
    NOTIFY new_judge_task;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER submissions_insert_notify_new_judge_task
    AFTER INSERT ON submissions
    FOR EACH STATEMENT EXECUTE PROCEDURE notify_new_judge_task();

CREATE TRIGGER testcases_insert_notify_new_judge_task
    AFTER INSERT ON testcases
    FOR EACH STATEMENT EXECUTE PROCEDURE notify_new_judge_task();

CREATE TRIGGER results_judges_delete_notify_new_judge_task
    AFTER DELETE OR TRUNCATE ON result_judges
    FOR EACH STATEMENT EXECUTE PROCEDURE notify_new_judge_task();

CREATE TRIGGER results_delete_notify_new_judge_task
    AFTER DELETE OR TRUNCATE ON results
    FOR EACH STATEMENT EXECUTE PROCEDURE notify_new_judge_task();

GRANT SELECT ON checkers TO toyojweb;
GRANT SELECT ON languages TO toyojweb;
GRANT SELECT, INSERT ON users TO toyojweb;
GRANT SELECT ON user_permissions TO toyojweb;
GRANT SELECT, INSERT, UPDATE ON password_logins TO toyojweb;
GRANT SELECT, INSERT, UPDATE ON problems TO toyojweb;
GRANT SELECT, INSERT, DELETE, UPDATE ON testcases TO toyojweb;
GRANT SELECT, INSERT ON submissions TO toyojweb;
GRANT SELECT ON result_judges TO toyojweb;
GRANT SELECT ON results TO toyojweb;
GRANT SELECT ON problems_view TO toyojweb;
GRANT SELECT ON results_view TO toyojweb;
GRANT SELECT ON submission_view TO toyojweb;
GRANT USAGE ON users_id_seq TO toyojweb;
GRANT USAGE ON problems_id_seq TO toyojweb;
GRANT USAGE ON testcases_id_seq TO toyojweb;
GRANT USAGE ON submissions_id_seq TO toyojweb;

GRANT SELECT ON results_view TO toyojjudge;
GRANT SELECT, INSERT, DELETE ON result_judges TO toyojjudge;
GRANT SELECT ON submissions TO toyojjudge;
GRANT SELECT ON testcases TO toyojjudge;
GRANT SELECT, INSERT ON results TO toyojjudge;

-- data

INSERT INTO checkers (name) VALUES
    ('exact');

INSERT INTO languages (name) VALUES
    ('C++14'),
    ('Haskell');
