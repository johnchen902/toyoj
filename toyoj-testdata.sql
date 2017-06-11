INSERT INTO checkers (name) VALUES
    ('exact');

INSERT INTO languages (name) VALUES
    ('C++14');

INSERT INTO users (username) VALUES
    ('test'),
    ('<br>');

INSERT INTO user_permissions (user_id, permission_name) VALUES
    (1, 'newproblem');

INSERT INTO password_logins (user_id, password_hash) VALUES
    (1, '$2y$10$a/MiX7vz73M4ilF1JeLVZuIiYnx14iBzb/dedhwlVobjhhIvLl1fm');

INSERT INTO problems (title, statement, ready, manager_id) VALUES
    ('Simple Negation',
    E'### Description\nNegate a given integer.\n### Input Format\nAn integer \\\\(x\\\\). (\\\\(-1 \\\\le x \\\\le 1\\\\))\n### Output Format\nAn integer on a single line.\n### Sample Input\n```\n1\n```\n### Sample Output\n```\n-1\n```\n',
    true, 1),
    ('Problem <br>',
    E'<script>alert(1)</script>\n',
    false, 1);

INSERT INTO subtasks (problem_id, score) VALUES
    (1, 20),
    (1, 80);

INSERT INTO testcases (problem_id, time_limit, memory_limit, checker_name, input, output) VALUES
    (1, 1000, 262144, 'exact', E'1\n', E'-1\n'),
    (1, 1000, 262144, 'exact', E'0\n', E'0\n'),
    (1, 1000, 262144, 'exact', E'-1\n', E'1\n');

INSERT INTO subtask_testcases (problem_id, subtask_id, testcase_id) VALUES
    (1, 1, 3),
    (1, 2, 1),
    (1, 2, 2),
    (1, 2, 3);

INSERT INTO submissions (problem_id, submitter_id, language_name, code) VALUES
    (1, 1, 'C++14',
    E'#include <cstdio>\nint main() {\n    std::puts("1");\n}\n'),
    (1, 1, 'C++14',
    E'#include <iostream>\nint main() {\n    int x;\n    std::cin >> x;\n    std::cout << -x << std::endl;\n}\n');

INSERT INTO result_judges (problem_id, submission_id, testcase_id, judge_name) VALUES
    (1, 1, 1, 'Manual'),
    (1, 1, 2, 'Manual'),
    (1, 1, 3, 'Manual');

INSERT INTO results (problem_id, submission_id, testcase_id, accepted, time, memory, verdict) VALUES
    (1, 1, 1, false, 1, 2456, 'WA'),
    (1, 1, 2, false, 1, 2476, 'WA'),
    (1, 1, 3, true, 1, 2380, 'AC');
