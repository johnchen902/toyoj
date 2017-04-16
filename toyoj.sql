--
-- PostgreSQL database dump
--

-- Dumped from database version 9.6.1
-- Dumped by pg_dump version 9.6.1

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
    visible boolean NOT NULL
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
    pid integer NOT NULL,
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
    pid integer NOT NULL,
    subtaskid integer NOT NULL,
    testcaseid integer NOT NULL
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
    checker character varying(32) NOT NULL
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE users (
    uid integer NOT NULL,
    username character varying(32) NOT NULL,
    register_date timestamp with time zone DEFAULT now() NOT NULL
);


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

COPY problems (pid, statement, title, create_date, manager, visible) FROM stdin;
\.


--
-- Name: problems_pid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('problems_pid_seq', 1, false);


--
-- Data for Name: results; Type: TABLE DATA; Schema: public; Owner: -
--

COPY results (pid, testcaseid, sid, accepted, "time", memory, judge_time, verdict) FROM stdin;
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
-- Data for Name: subtasktestcases; Type: TABLE DATA; Schema: public; Owner: -
--

COPY subtasktestcases (pid, subtaskid, testcaseid) FROM stdin;
\.


--
-- Data for Name: testcases; Type: TABLE DATA; Schema: public; Owner: -
--

COPY testcases (pid, testcaseid, input, output, time_limit, memory_limit, checker) FROM stdin;
\.


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
    ADD CONSTRAINT results_pkey PRIMARY KEY (pid, testcaseid, sid);


--
-- Name: submissions submissions_pid_sid_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY submissions
    ADD CONSTRAINT submissions_pid_sid_key UNIQUE (pid, sid);


--
-- Name: submissions submissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY submissions
    ADD CONSTRAINT submissions_pkey PRIMARY KEY (sid);


--
-- Name: subtasks subtasks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY subtasks
    ADD CONSTRAINT subtasks_pkey PRIMARY KEY (pid, subtaskid);


--
-- Name: subtasktestcases subtasktestcases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY subtasktestcases
    ADD CONSTRAINT subtasktestcases_pkey PRIMARY KEY (pid, subtaskid, testcaseid);


--
-- Name: testcases testcases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY testcases
    ADD CONSTRAINT testcases_pkey PRIMARY KEY (pid, testcaseid);


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
-- Name: results results_pid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY results
    ADD CONSTRAINT results_pid_fkey FOREIGN KEY (pid) REFERENCES problems(pid);


--
-- Name: results results_pid_fkey1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY results
    ADD CONSTRAINT results_pid_fkey1 FOREIGN KEY (pid, testcaseid) REFERENCES testcases(pid, testcaseid);


--
-- Name: results results_pid_fkey2; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY results
    ADD CONSTRAINT results_pid_fkey2 FOREIGN KEY (pid, sid) REFERENCES submissions(pid, sid);


--
-- Name: results results_sid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY results
    ADD CONSTRAINT results_sid_fkey FOREIGN KEY (sid) REFERENCES submissions(sid);


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
-- Name: subtasktestcases subtasktestcases_pid_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY subtasktestcases
    ADD CONSTRAINT subtasktestcases_pid_fkey FOREIGN KEY (pid) REFERENCES problems(pid);


--
-- Name: subtasktestcases subtasktestcases_pid_fkey1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY subtasktestcases
    ADD CONSTRAINT subtasktestcases_pid_fkey1 FOREIGN KEY (pid, subtaskid) REFERENCES subtasks(pid, subtaskid);


--
-- Name: subtasktestcases subtasktestcases_pid_fkey2; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY subtasktestcases
    ADD CONSTRAINT subtasktestcases_pid_fkey2 FOREIGN KEY (pid, testcaseid) REFERENCES testcases(pid, testcaseid);


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
-- PostgreSQL database dump complete
--

