# 아키텍처

## 책임 경계

- Slim 라우트와 컨트롤러는 HTTP 요청·응답, JSON, 상태 코드와 상관관계 ID를 처리한다.
- 인증 미들웨어는 로컬 Bearer 토큰이나 플레이어 HMAC 자격 증명을 서버가 결정한 주체와 `source` 컨텍스트로 변환한다. 요청 본문은 `source`를 지정할 수 없다.
- `EventInput`은 형식이 잘못됐거나 알 수 없는 필드를 포함하거나 계약에 맞지 않는 입력을 서비스 로직 전에 거부한다.
- `ProgressService`는 대상 사전 검사, 재시도 범위, 중복 판정과 현재 상태 반영 순서를 조정한다.
- `ProgressRepository`는 매개변수화 SQL과 SQL Server 잠금을 담당한다. ORM과 DI 컨테이너는 사용하지 않는다.

## 쓰기 경로

저장소는 쓰기 트랜잭션을 열기 전에 학습자, 강의, 수강 기간과 미래 시각 제한을 확인한다. 트랜잭션에서는 `learning_events`에 먼저 INSERT한 뒤 `UPDLOCK, HOLDLOCK, INDEX(UQ_lecture_progress_learner_lecture)`로 `lecture_progress`를 조회하고 현재 상태를 삽입하거나 갱신한다.

중복 키 오류 2601/2627이 발생하면 먼저 롤백하고 트랜잭션 밖에서 `payload_hash`를 다시 조회한다. 해시가 같으면 멱등 중복 응답을 반환하고 다르면 409를 반환한다. 오류 1205와 3960은 전체 트랜잭션을 한 번 재시도한다. 트리거 결과셋이 생길 수 있는 SQL은 모든 결과셋을 소비해 PDO_SQLSRV가 SQL Server 오류를 재시도 경계까지 전달하도록 한다.

같은 `session_id`에서는 `(sequence_no, occurred_at, event_seq)`, 서로 다른 `session_id`에서는 `(occurred_at, received_at, event_seq)`가 큰 이벤트를 최신으로 본다. 과거 이벤트도 원장에 남고 `furthest_position_seconds`를 늘릴 수 있지만, 최신 이벤트만 이어보기 위치와 마지막 이벤트 순서 필드를 바꾼다. `completed_at`은 최초 완료 시각만 기록한다.

## 읽기 경계

보호자 조회 인증은 먼저 토큰을 한 보호자 주체에 연결한다. 경로의 `guardianId`가 해당 주체와 일치하고 `guardian_links`가 학습자 조회를 허용해야 진행 상태를 반환한다. Classic ASP 어댑터는 별도의 읽기 전용 연동 경계로 유지하며, 소스와 JSON 응답은 계약 테스트로 확인한다.
