IF OBJECT_ID(N'dbo.guardians', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.guardians (
        guardian_id BIGINT NOT NULL,
        display_name NVARCHAR(100) NOT NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_guardians_created_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_guardians PRIMARY KEY (guardian_id)
    );
END;

IF OBJECT_ID(N'dbo.learners', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.learners (
        learner_id BIGINT NOT NULL,
        display_name NVARCHAR(100) NOT NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_learners_created_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_learners PRIMARY KEY (learner_id)
    );
END;

IF OBJECT_ID(N'dbo.courses', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.courses (
        course_id BIGINT NOT NULL,
        title NVARCHAR(200) NOT NULL,
        is_active BIT NOT NULL CONSTRAINT DF_courses_is_active DEFAULT 1,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_courses_created_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_courses PRIMARY KEY (course_id)
    );
END;

IF OBJECT_ID(N'dbo.lectures', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.lectures (
        lecture_id BIGINT NOT NULL,
        course_id BIGINT NOT NULL,
        title NVARCHAR(200) NOT NULL,
        lecture_order INT NOT NULL,
        duration_seconds INT NOT NULL,
        is_active BIT NOT NULL CONSTRAINT DF_lectures_is_active DEFAULT 1,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_lectures_created_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_lectures PRIMARY KEY (lecture_id),
        CONSTRAINT FK_lectures_course FOREIGN KEY (course_id) REFERENCES dbo.courses(course_id),
        CONSTRAINT CK_lectures_order CHECK (lecture_order > 0),
        CONSTRAINT CK_lectures_duration CHECK (duration_seconds > 0),
        CONSTRAINT UQ_lectures_course_order UNIQUE (course_id, lecture_order)
    );
END;

IF OBJECT_ID(N'dbo.enrollments', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.enrollments (
        learner_id BIGINT NOT NULL,
        course_id BIGINT NOT NULL,
        enrollment_status NVARCHAR(20) NOT NULL,
        starts_at DATETIME2(3) NOT NULL,
        ends_at DATETIME2(3) NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_enrollments_created_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_enrollments PRIMARY KEY (learner_id, course_id),
        CONSTRAINT FK_enrollments_learner FOREIGN KEY (learner_id) REFERENCES dbo.learners(learner_id),
        CONSTRAINT FK_enrollments_course FOREIGN KEY (course_id) REFERENCES dbo.courses(course_id),
        CONSTRAINT CK_enrollments_status CHECK (enrollment_status IN (N'ACTIVE', N'EXPIRED', N'CANCELLED')),
        CONSTRAINT CK_enrollments_dates CHECK (ends_at IS NULL OR ends_at >= starts_at)
    );
END;

IF OBJECT_ID(N'dbo.guardian_links', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.guardian_links (
        guardian_id BIGINT NOT NULL,
        learner_id BIGINT NOT NULL,
        relationship_type NVARCHAR(30) NOT NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_guardian_links_created_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_guardian_links PRIMARY KEY (guardian_id, learner_id),
        CONSTRAINT FK_guardian_links_guardian FOREIGN KEY (guardian_id) REFERENCES dbo.guardians(guardian_id),
        CONSTRAINT FK_guardian_links_learner FOREIGN KEY (learner_id) REFERENCES dbo.learners(learner_id)
    );
END;

IF OBJECT_ID(N'dbo.learning_events', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.learning_events (
        event_seq BIGINT IDENTITY(1, 1) NOT NULL,
        source NVARCHAR(40) NOT NULL,
        event_id NVARCHAR(100) NOT NULL,
        learner_id BIGINT NOT NULL,
        lecture_id BIGINT NOT NULL,
        session_id NVARCHAR(100) NOT NULL,
        sequence_no BIGINT NOT NULL,
        event_type NVARCHAR(20) NOT NULL,
        position_seconds INT NOT NULL,
        occurred_at DATETIME2(3) NOT NULL,
        received_at DATETIME2(3) NOT NULL CONSTRAINT DF_learning_events_received_at DEFAULT SYSUTCDATETIME(),
        payload_hash CHAR(64) NOT NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_learning_events_created_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_learning_events PRIMARY KEY (event_seq),
        CONSTRAINT UQ_learning_events_source_event_id UNIQUE (source, event_id),
        CONSTRAINT FK_learning_events_learner FOREIGN KEY (learner_id) REFERENCES dbo.learners(learner_id),
        CONSTRAINT FK_learning_events_lecture FOREIGN KEY (lecture_id) REFERENCES dbo.lectures(lecture_id),
        CONSTRAINT CK_learning_events_sequence CHECK (sequence_no >= 0),
        CONSTRAINT CK_learning_events_type CHECK (event_type IN (N'CHECKPOINT', N'COMPLETED')),
        CONSTRAINT CK_learning_events_position CHECK (position_seconds >= 0)
    );
END;

IF OBJECT_ID(N'dbo.lecture_progress', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.lecture_progress (
        progress_id BIGINT IDENTITY(1, 1) NOT NULL,
        learner_id BIGINT NOT NULL,
        lecture_id BIGINT NOT NULL,
        resume_position_seconds INT NOT NULL CONSTRAINT DF_lecture_progress_resume DEFAULT 0,
        furthest_position_seconds INT NOT NULL CONSTRAINT DF_lecture_progress_furthest DEFAULT 0,
        last_studied_at DATETIME2(3) NULL,
        last_session_id NVARCHAR(100) NULL,
        last_sequence_no BIGINT NULL,
        last_received_at DATETIME2(3) NULL,
        last_event_seq BIGINT NULL,
        completed_at DATETIME2(3) NULL,
        created_at DATETIME2(3) NOT NULL CONSTRAINT DF_lecture_progress_created_at DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(3) NOT NULL CONSTRAINT DF_lecture_progress_updated_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_lecture_progress PRIMARY KEY (progress_id),
        CONSTRAINT UQ_lecture_progress_learner_lecture UNIQUE (learner_id, lecture_id),
        CONSTRAINT FK_lecture_progress_learner FOREIGN KEY (learner_id) REFERENCES dbo.learners(learner_id),
        CONSTRAINT FK_lecture_progress_lecture FOREIGN KEY (lecture_id) REFERENCES dbo.lectures(lecture_id),
        CONSTRAINT FK_lecture_progress_last_event FOREIGN KEY (last_event_seq) REFERENCES dbo.learning_events(event_seq),
        CONSTRAINT CK_lecture_progress_positions CHECK (
            resume_position_seconds >= 0
            AND furthest_position_seconds >= resume_position_seconds
        )
    );
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.lectures') AND name = N'IX_lectures_course_order')
BEGIN
    CREATE INDEX IX_lectures_course_order ON dbo.lectures (course_id, lecture_order);
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.enrollments') AND name = N'IX_enrollments_course_learner')
BEGIN
    CREATE INDEX IX_enrollments_course_learner ON dbo.enrollments (course_id, learner_id) INCLUDE (enrollment_status, starts_at, ends_at);
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.guardian_links') AND name = N'IX_guardian_links_learner_guardian')
BEGIN
    CREATE INDEX IX_guardian_links_learner_guardian ON dbo.guardian_links (learner_id, guardian_id);
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.learning_events') AND name = N'IX_learning_events_learner_lecture_occurred')
BEGIN
    CREATE INDEX IX_learning_events_learner_lecture_occurred ON dbo.learning_events (learner_id, lecture_id, occurred_at DESC, event_seq DESC);
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.lecture_progress') AND name = N'IX_lecture_progress_learner_updated')
BEGIN
    CREATE INDEX IX_lecture_progress_learner_updated ON dbo.lecture_progress (learner_id, updated_at DESC) INCLUDE (lecture_id, completed_at);
END;
