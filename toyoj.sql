--
-- PostgreSQL database dump
--

-- Dumped from database version 9.6.2
-- Dumped by pg_dump version 9.6.2

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SET check_function_bodies = false;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: plpgsql; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS plpgsql WITH SCHEMA pg_catalog;


--
-- Name: EXTENSION plpgsql; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION plpgsql IS 'PL/pgSQL procedural language';


SET search_path = public, pg_catalog;

SET default_tablespace = '';

SET default_with_oids = false;

--
-- Name: checkers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE checkers (
    name character varying(32) NOT NULL
);


--
-- Name: judgers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE judgers (
    testcaseid integer NOT NULL,
    sid integer NOT NULL,
    judge_name character varying(32) NOT NULL
);


--
-- Name: languages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE languages (
    name character varying(32) NOT NULL
);


--
-- Name: passwords; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE passwords (
    uid integer NOT NULL,
    hash character varying(256) NOT NULL
);


--
-- Name: problems; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE problems (
    pid integer NOT NULL,
    statement text NOT NULL,
    title character varying(128) NOT NULL,
    create_date timestamp with time zone DEFAULT now() NOT NULL,
    manager integer NOT NULL,
    ready boolean DEFAULT false NOT NULL
);


--
-- Name: problems_pid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE problems_pid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: problems_pid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE problems_pid_seq OWNED BY problems.pid;


--
-- Name: results; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE results (
    testcaseid integer NOT NULL,
    sid integer NOT NULL,
    accepted boolean NOT NULL,
    "time" integer NOT NULL,
    memory integer NOT NULL,
    judge_time timestamp with time zone DEFAULT now() NOT NULL,
    verdict character varying(3) NOT NULL
);


--
-- Name: submissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE submissions (
    sid integer NOT NULL,
    submitter integer NOT NULL,
    language character varying(32) NOT NULL,
    code text NOT NULL,
    submit_time timestamp with time zone DEFAULT now() NOT NULL,
    pid integer NOT NULL
);


--
-- Name: testcases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE testcases (
    pid integer NOT NULL,
    testcaseid integer NOT NULL,
    input text NOT NULL,
    output text NOT NULL,
    time_limit integer NOT NULL,
    memory_limit integer NOT NULL,
    checker character varying(32) NOT NULL,
    CONSTRAINT testcases_memory_limit_check CHECK ((memory_limit >= 4)),
    CONSTRAINT testcases_time_limit_check CHECK ((time_limit >= 1))
);


--
-- Name: results_view; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW results_view AS
 SELECT submissions.sid,
    testcases.testcaseid,
    results.accepted,
    results."time",
    results.memory,
    results.judge_time,
    results.verdict,
    judgers.judge_name
   FROM (((submissions
     JOIN testcases USING (pid))
     LEFT JOIN results USING (sid, testcaseid))
     LEFT JOIN judgers USING (sid, testcaseid));


--
-- Name: submissions_sid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE submissions_sid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: submissions_sid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE submissions_sid_seq OWNED BY submissions.sid;


--
-- Name: subtasks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE subtasks (
    pid integer NOT NULL,
    subtaskid integer NOT NULL,
    score integer NOT NULL
);


--
-- Name: subtasktestcases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE subtasktestcases (
    subtaskid integer NOT NULL,
    testcaseid integer NOT NULL
);


--
-- Name: subtask_results_view; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW subtask_results_view AS
 SELECT submissions.sid,
    subtasks.subtaskid,
    every((results.accepted IS TRUE)) AS accepted,
    bool_or((results.accepted IS FALSE)) AS rejected
   FROM (((submissions
     JOIN subtasks USING (pid))
     JOIN subtasktestcases USING (subtaskid))
     LEFT JOIN results USING (sid, testcaseid))
  GROUP BY submissions.sid, subtasks.subtaskid;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE users (
    uid integer NOT NULL,
    username character varying(32) NOT NULL,
    register_date timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: submissions_view; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW submissions_view AS
 SELECT s.sid,
    s.pid,
    p.title,
    s.submitter,
    u.username AS submitter_name,
    s.language,
    s.code,
    s.submit_time,
    r.accepted,
    r.rejected,
    r."time",
    r.memory,
    r.judge_time,
    t.minscore,
    t.maxscore,
    t.fullscore
   FROM ((((submissions s
     JOIN problems p USING (pid))
     JOIN users u ON ((s.submitter = u.uid)))
     LEFT JOIN ( SELECT results_view.sid,
            every((results_view.accepted IS TRUE)) AS accepted,
            bool_or((results_view.accepted IS FALSE)) AS rejected,
            max(results_view."time") AS "time",
            max(results_view.memory) AS memory,
            max(results_view.judge_time) AS judge_time
           FROM results_view
          GROUP BY results_view.sid) r USING (sid))
     LEFT JOIN ( SELECT subtask_results_view.sid,
            sum(
                CASE
                    WHEN subtask_results_view.accepted THEN subtasks.score
                    ELSE 0
                END) AS minscore,
            sum(
                CASE
                    WHEN subtask_results_view.rejected THEN 0
                    ELSE subtasks.score
                END) AS maxscore,
            sum(subtasks.score) AS fullscore
           FROM (subtask_results_view
             JOIN subtasks USING (subtaskid))
          GROUP BY subtask_results_view.sid) t USING (sid));


--
-- Name: subtask_results_view_2; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW subtask_results_view_2 AS
 SELECT subtask_results_view.sid,
    subtask_results_view.subtaskid,
    subtask_results_view.accepted,
    subtask_results_view.rejected,
        CASE
            WHEN subtask_results_view.accepted THEN subtasks.score
            ELSE 0
        END AS minscore,
        CASE
            WHEN subtask_results_view.rejected THEN 0
            ELSE subtasks.score
        END AS maxscore,
    subtasks.score AS fullscore
   FROM (subtask_results_view
     JOIN subtasks USING (subtaskid));


--
-- Name: subtasks_subtaskid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE subtasks_subtaskid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subtasks_subtaskid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE subtasks_subtaskid_seq OWNED BY subtasks.subtaskid;


--
-- Name: subtasks_view; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE subtasks_view (
    subtaskid integer,
    score integer,
    testcaseids integer[],
    pid integer
);

ALTER TABLE ONLY subtasks_view REPLICA IDENTITY NOTHING;


--
-- Name: testcases_testcaseid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE testcases_testcaseid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: testcases_testcaseid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE testcases_testcaseid_seq OWNED BY testcases.testcaseid;


--
-- Name: users_uid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE users_uid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_uid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE users_uid_seq OWNED BY users.uid;


--
-- Name: problems pid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY problems ALTER COLUMN pid SET DEFAULT nextval('problems_pid_seq'::regclass);


--
-- Name: submissions sid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY submissions ALTER COLUMN sid SET DEFAULT nextval('submissions_sid_seq'::regclass);


--
-- Name: subtasks subtaskid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY subtasks ALTER COLUMN subtaskid SET DEFAULT nextval('subtasks_subtaskid_seq'::regclass);


--
-- Name: testcases testcaseid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY testcases ALTER COLUMN testcaseid SET DEFAULT nextval('testcases_testcaseid_seq'::regclass);


--
-- Name: users uid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY users ALTER COLUMN uid SET DEFAULT nextval('users_uid_seq'::regclass);


--
-- Data for Name: checkers; Type: TABLE DATA; Schema: public; Owner: -
--

COPY checkers (name) FROM stdin;
exact
\.


--
-- Data for Name: judgers; Type: TABLE DATA; Schema: public; Owner: -
--

COPY judgers (testcaseid, sid, judge_name) FROM stdin;
\.


--
-- Data for Name: languages; Type: TABLE DATA; Schema: public; Owner: -
--

COPY languages (name) FROM stdin;
C++14
\.


--
-- Data for Name: passwords; Type: TABLE DATA; Schema: public; Owner: -
--

COPY passwords (uid, hash) FROM stdin;
\.


--
-- Data for Name: problems; Type: TABLE DATA; Schema: public; Owner: -
--

COPY problems (pid, statement, title, create_date, manager, ready) FROM stdin;
\.


--
-- Name: problems_pid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('problems_pid_seq', 1, false);


--
-- Data for Name: results; Type: TABLE DATA; Schema: public; Owner: -
--

COPY results (testcaseid, sid, accepted, "time", memory, judge_time, verdict) FROM stdin;
\.


--
-- Data for Name: submissions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY submissions (sid, submitter, language, code, submit_time, pid) FROM stdin;
\.


--
-- Name: submissions_sid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('submissions_sid_seq', 1, false);


--
-- Data for Name: subtasks; Type: TABLE DATA; Schema: public; Owner: -
--

COPY subtasks (pid, subtaskid, score) FROM stdin;
\.


--
-- Name: subtasks_subtaskid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('subtasks_subtaskid_seq', 1, false);


--
-- Data for Name: subtasktestcases; Type: TABLE DATA; Schema: public; Owner: -
--

COPY subtasktestcases (subtaskid, testcaseid) FROM stdin;
\.


--
-- Data for Name: testcases; Type: TABLE DATA; Schema: public; Owner: -
--

COPY testcases (pid, testcaseid, input, output, time_limit, memory_limit, checker) FROM stdin;
\.


--
-- Name: testcases_testcaseid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('testcases_testcaseid_seq', 1, false);


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: -
--

COPY users (uid, username, register_date) FROM stdin;
\.


--
-- Name: users_uid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('users_uid_seq', 1, false);


--
-- Name: checkers checkers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY checkers
    ADD CONSTRAINT checkers_pkey PRIMARY KEY (name);


--
-- Name: judgers judgers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY judgers
    ADD CONSTRAINT judgers_pkey PRIMARY KEY (sid, testcaseid);


--
-- Name: languages languages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY languages
    ADD CONSTRAINT languages_pkey PRIMARY KEY (name);


--
-- Name: passwords passwords_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY passwords
    ADD CONSTRAINT passwords_pkey PRIMARY KEY (uid);


--
-- Name: problems problems_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY problems
    ADD CONSTRAINT problems_pkey PRIMARY KEY (pid);


--
-- Name: problems problems_title_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY problems
    ADD CONSTRAINT problems_title_key UNIQUE (title);


--
-- Name: results results_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY results
    ADD CONSTRAINT results_pkey PRIMARY KEY (sid, testcaseid);


--
-- Name: submissions submissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY submissions
    ADD CONSTRAINT submissions_pkey PRIMARY KEY (sid);


--
-- Name: subtasks subtasks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY subtasks
    ADD CONSTRAINT subtasks_pkey PRIMARY KEY (subtaskid);


--
-- Name: subtasktestcases subtasktestcases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY subtasktestcases
    ADD CONSTRAINT subtasktestcases_pkey PRIMARY KEY (subtaskid, testcaseid);


--
-- Name: testcases testcases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY testcases
    ADD CONSTRAINT testcases_pkey PRIMARY KEY (testcaseid);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY users
    ADD CONSTRAINT users_pkey PRIMARY KEY (uid);


--
-- Name: users users_username_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY users
    ADD CONSTRAINT users_username_key UNIQUE (username);


--
-- Name: subtasks_view _RETURN; Type: RULE; Schema: public; Owner: -
--

CREATE RULE "_RETURN" AS
    ON SELECT TO subtasks_view DO INSTEAD  SELECT subtasks.subtaskid,
    subtasks.score,
    array_agg(subtasktestcases.testcaseid) AS testcaseids,
    subtasks.pid
   FROM (subtasks
     LEFT JOIN subtasktestcases USING (subtaskid))
  GROUP BY subtasks.subtaskid;


--
-- Name: judgers judgers_sid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY judgers
    ADD CONSTRAINT judgers_sid_fkey FOREIGN KEY (sid) REFERENCES submissions(sid);


--
-- Name: judgers judgers_testcaseid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY judgers
    ADD CONSTRAINT judgers_testcaseid_fkey FOREIGN KEY (testcaseid) REFERENCES testcases(testcaseid);


--
-- Name: passwords passwords_uid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY passwords
    ADD CONSTRAINT passwords_uid_fkey FOREIGN KEY (uid) REFERENCES users(uid);


--
-- Name: problems problems_manager_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY problems
    ADD CONSTRAINT problems_manager_fkey FOREIGN KEY (manager) REFERENCES users(uid);


--
-- Name: results results_sid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY results
    ADD CONSTRAINT results_sid_fkey FOREIGN KEY (sid) REFERENCES submissions(sid);


--
-- Name: results results_testcaseid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY results
    ADD CONSTRAINT results_testcaseid_fkey FOREIGN KEY (testcaseid) REFERENCES testcases(testcaseid);


--
-- Name: submissions submissions_language_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY submissions
    ADD CONSTRAINT submissions_language_fkey FOREIGN KEY (language) REFERENCES languages(name);


--
-- Name: submissions submissions_pid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY submissions
    ADD CONSTRAINT submissions_pid_fkey FOREIGN KEY (pid) REFERENCES problems(pid);


--
-- Name: submissions submissions_submitter_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY submissions
    ADD CONSTRAINT submissions_submitter_fkey FOREIGN KEY (submitter) REFERENCES users(uid);


--
-- Name: subtasks subtasks_pid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY subtasks
    ADD CONSTRAINT subtasks_pid_fkey FOREIGN KEY (pid) REFERENCES problems(pid);


--
-- Name: subtasktestcases subtasktestcases_subtaskid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY subtasktestcases
    ADD CONSTRAINT subtasktestcases_subtaskid_fkey FOREIGN KEY (subtaskid) REFERENCES subtasks(subtaskid);


--
-- Name: subtasktestcases subtasktestcases_testcaseid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY subtasktestcases
    ADD CONSTRAINT subtasktestcases_testcaseid_fkey FOREIGN KEY (testcaseid) REFERENCES testcases(testcaseid);


--
-- Name: testcases testcases_checker_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY testcases
    ADD CONSTRAINT testcases_checker_fkey FOREIGN KEY (checker) REFERENCES checkers(name);


--
-- Name: testcases testcases_pid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY testcases
    ADD CONSTRAINT testcases_pid_fkey FOREIGN KEY (pid) REFERENCES problems(pid);


--
-- Name: passwords; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT ON TABLE passwords TO toyojweb;


--
-- Name: problems; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,UPDATE ON TABLE problems TO toyojweb;


--
-- Name: submissions; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,INSERT ON TABLE submissions TO toyojweb;


--
-- Name: testcases; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT,UPDATE ON TABLE testcases TO toyojweb;


--
-- Name: results_view; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT ON TABLE results_view TO toyojweb;


--
-- Name: submissions_sid_seq; Type: ACL; Schema: public; Owner: -
--

GRANT USAGE ON SEQUENCE submissions_sid_seq TO toyojweb;


--
-- Name: users; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT ON TABLE users TO toyojweb;


--
-- Name: submissions_view; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT ON TABLE submissions_view TO toyojweb;


--
-- Name: subtask_results_view_2; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT ON TABLE subtask_results_view_2 TO toyojweb;


--
-- Name: subtasks_view; Type: ACL; Schema: public; Owner: -
--

GRANT SELECT ON TABLE subtasks_view TO toyojweb;


--
-- PostgreSQL database dump complete
--

