IF NOT EXISTS (SELECT 1 FROM dbo.guardians WHERE guardian_id = 1001)
BEGIN
    INSERT INTO dbo.guardians (guardian_id, display_name) VALUES (1001, N'Seed Guardian');
END;

IF NOT EXISTS (SELECT 1 FROM dbo.learners WHERE learner_id = 2001)
BEGIN
    INSERT INTO dbo.learners (learner_id, display_name) VALUES (2001, N'Seed Learner');
END;

IF NOT EXISTS (SELECT 1 FROM dbo.courses WHERE course_id = 3001)
BEGIN
    INSERT INTO dbo.courses (course_id, title, is_active) VALUES (3001, N'Foundation Course', 1);
END;

IF NOT EXISTS (SELECT 1 FROM dbo.lectures WHERE lecture_id = 4001)
BEGIN
    INSERT INTO dbo.lectures (lecture_id, course_id, title, lecture_order, duration_seconds, is_active)
    VALUES (4001, 3001, N'Foundation Lecture', 1, 600, 1);
END;

IF NOT EXISTS (SELECT 1 FROM dbo.enrollments WHERE learner_id = 2001 AND course_id = 3001)
BEGIN
    INSERT INTO dbo.enrollments (learner_id, course_id, enrollment_status, starts_at, ends_at)
    VALUES (2001, 3001, N'ACTIVE', '2026-01-01T00:00:00.000', '2026-12-31T23:59:59.999');
END;

IF NOT EXISTS (SELECT 1 FROM dbo.guardian_links WHERE guardian_id = 1001 AND learner_id = 2001)
BEGIN
    INSERT INTO dbo.guardian_links (guardian_id, learner_id, relationship_type)
    VALUES (1001, 2001, N'PARENT');
END;

IF NOT EXISTS (SELECT 1 FROM dbo.lecture_progress WHERE learner_id = 2001 AND lecture_id = 4001)
BEGIN
    INSERT INTO dbo.lecture_progress (
        learner_id,
        lecture_id,
        resume_position_seconds,
        furthest_position_seconds,
        last_studied_at,
        last_session_id,
        last_sequence_no,
        last_received_at,
        last_event_seq,
        completed_at
    )
    VALUES (
        2001,
        4001,
        120,
        180,
        '2026-07-01T10:00:00.000',
        N'seed-session-001',
        3,
        '2026-07-01T10:00:01.000',
        NULL,
        NULL
    );
END;
