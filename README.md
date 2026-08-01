# EduSync Learning API

웹·모바일 플레이어의 학습 이벤트를 수집하고 강의별 진행 상태를 관리하는 API다. PHP 8.3, Slim 4, SQL Server 2022를 사용하며 멱등 처리, 늦게 도착한 이벤트, 동시 쓰기, 보호자 권한 조회를 다룬다. 기존 IIS 환경과 연동할 수 있도록 읽기 전용 Classic ASP 어댑터도 제공한다.

## 프로젝트 개요

| 항목 | 내용 |
| --- | --- |
| 프로젝트명 | EduSync Learning API |
| 개발 기간 | 2026-07-24 ~ 2026-07-26 |
| 주요 기술 | PHP 8.3, Slim 4, SQL Server 2022, Docker Compose |
| API 문서 | OpenAPI 3.0, Swagger UI |
| 호환 환경 | Windows IIS 10, Classic ASP |
| 라이선스 | MIT |

## 핵심 기능

- Bearer 인증을 사용하는 학습 이벤트 수집
- HMAC-SHA256 서명을 사용하는 플레이어 콜백 수신
- `(source, event_id)` 기준 멱등 처리와 충돌 감지
- 재생 위치, 최대 시청 위치, 최초 완료 시각의 독립적인 갱신
- 보호자·학습자 연결 관계를 확인하는 진행 조회
- SQL Server 잠금과 트랜잭션 재시도를 적용한 동시성 제어
- 내장 Swagger UI와 재실행 가능한 통합·동시성 테스트

## 기술 스택

| 구분 | 기술 |
| --- | --- |
| API | PHP 8.3, Slim 4, PSR-7 |
| 데이터베이스 | SQL Server 2022, PDO_SQLSRV |
| 인증 | Bearer 토큰, HMAC-SHA256 |
| 문서 | OpenAPI 3.0, Swagger UI 5.32.6 |
| 실행 환경 | Docker Compose, Apache |
| 호환 환경 | IIS 10, Classic ASP, ADO, Microsoft OLE DB Driver 19 |

## 전체 처리 흐름

![학습 이벤트 전체 파이프라인](docs/readme/pipeline.png)

## 시스템 구성

![EduSync 시스템 아키텍처](docs/readme/architecture.png)

Classic ASP 어댑터는 PHP 쓰기 경로와 분리된 읽기 전용 경계로 유지한다.

## API

| 메서드 | 엔드포인트 | 인증 | 설명 |
| --- | --- | --- | --- |
| `GET` | `/health` | 없음 | API와 데이터베이스 상태 확인 |
| `POST` | `/api/v1/learning-events` | Bearer | 학습자와 `source`를 확인한 뒤 이벤트 수집 |
| `POST` | `/api/v1/player-events` | HMAC-SHA256 | 신뢰된 발행자 서명 검증 후 이벤트 수집. `learner_id` 소유권은 검사하지 않는다 |
| `GET` | `/api/v1/guardians/{guardianId}/learners/{learnerId}/progress` | Bearer | 보호자 연결 관계 확인 후 진행 상태 조회 |
| `GET` | `/progress.asp` | 없음, 루프백 전용 | 로컬 IIS에서 학습자와 강의 진행 상태 조회. ASP 소스가 루프백 아닌 호출자를 403으로 거부한다 |
| `GET` | `/docs` | 없음 | 프로젝트에 포함된 Swagger UI 제공 |

HMAC 서명 원문은 `X-Player-Timestamp + "\n" + raw HTTP request body`이며, 서명은 `sha256=<64 lowercase hex>` 형식이다. 서명 검증 전에는 요청 본문을 재직렬화하거나 공백을 정규화하지 않는다.

### 두 쓰기 경로의 인가 범위가 다르다

같은 컨트롤러를 쓰지만 인가 강도가 같지 않다. 의도한 설계다.

| 경로 | 자격 증명이 지목하는 주체 | `learner_id` 소유권 검사 |
| --- | --- | --- |
| `POST /api/v1/learning-events` | `APP_BEARER_LEARNER_ID` 학습자 한 명 | 검사한다. 다르면 403 |
| `POST /api/v1/player-events` | 발행자 하나(`PLAYER_EVENT_SOURCE`) | 검사하지 않는다 |

HMAC 자격 증명에는 학습자 식별자가 없다. 플레이어는 여러 학습자를 대신해 콜백을 보내는 서버 대 서버 발행자이므로 한 명에게 묶을 수 없다. 따라서 `PLAYER_HMAC_SECRET`을 가진 쪽은 임의의 `learner_id`로 이벤트를 쓸 수 있다.

인가가 없다는 뜻은 아니다. HMAC 경로도 학습자와 강의가 존재해야 하고(없으면 404) 해당 과정에 `ACTIVE` 수강이 있어야 하며(없으면 403) `occurred_at`이 수강 기간 안이어야 한다(벗어나면 422). 검사하지 않는 것은 소유권 하나다.

운영에서 이 경로를 열려면 `PLAYER_HMAC_SECRET`을 신뢰 경계 안에서만 유통해야 한다. 발행자를 신뢰할 수 없다면 자격 증명에 학습자 범위를 담고(예: 발행자별 허용 학습자 목록이나 서명 대상에 포함된 주체) 그 값과 `learner_id`를 대조하는 검사를 추가해야 한다. 이 저장소는 그 요구를 범위에 넣지 않았다.

`tests/integration.php`가 두 경로의 차이를 각각 고정한다. Bearer 경로가 다른 학습자를 403으로 거부하는 것과 HMAC 경로가 같은 학습자를 200으로 수락하는 것을 함께 확인한다.

## 데이터 일관성

![학습 이벤트 처리 흐름](docs/readme/event-flow.png)

이벤트를 `learning_events`에 먼저 기록한 뒤 `lecture_progress` 행을 `UPDLOCK,HOLDLOCK`으로 잠그고 현재 상태를 갱신한다. 두 변경은 하나의 트랜잭션에서 함께 커밋되거나 롤백된다.

동일한 `(source, event_id)`가 다시 들어오면 롤백한 뒤 `payload_hash`를 비교한다. 해시가 같으면 멱등 응답을 반환하고 다르면 409로 거부한다. SQL Server 오류 1205와 3960은 전체 트랜잭션을 한 번 재시도한다.

`session_id`가 같으면 `(sequence_no, occurred_at, event_seq)`, 다르면 `(occurred_at, received_at, event_seq)`로 최신 이벤트를 결정한다. `resume_position_seconds`는 최신 이벤트를 따르고, `furthest_position_seconds`는 최댓값을 유지하며, `completed_at`은 최초 완료 시각만 보존한다.

## 프로젝트 구조

```text
public/             Slim 진입점과 Swagger UI
src/                인증, 입력 검증, 서비스와 저장소
db/                 SQL Server 마이그레이션과 시드 데이터
legacy/             IIS Classic ASP 읽기 어댑터
tests/              계약, 통합, 동시성 테스트
scripts/            마이그레이션과 IIS 구성 스크립트
openapi.yaml        API 계약
docker-compose.yml  로컬 API·SQL Server 실행 구성
```

## 로컬 실행

`.env.example`을 복사한 뒤 로컬 개발용 비밀값을 설정한다. 예제의 정적 Bearer 토큰과 HMAC 비밀 키는 운영 인증을 대체하지 않는다.

```powershell
Copy-Item .env.example .env
docker compose config --quiet
docker compose up --build -d
Invoke-RestMethod http://localhost:8080/health
```

정상 응답은 `database: connected`, `driver: pdo_sqlsrv`, `probe: 1`을 포함한다. 데이터베이스 연결이나 probe가 실패하면 `/health`는 내부 오류를 노출하지 않고 503 JSON을 반환한다.

Swagger UI는 `http://localhost:8080/docs`에서 확인할 수 있다.

## 테스트와 검증

```powershell
docker compose exec -T app composer validate --no-check-publish
docker compose exec -T app composer check-platform-reqs
docker compose exec -T app composer test
docker compose exec -T app composer audit
```

`composer test`는 Classic ASP 소스·JSON 계약, HTTP+SQL Server 통합 테스트, DB 배리어 기반 동시성 테스트를 순서대로 실행한다. 테스트 데이터와 전용 테이블·트리거는 고유 식별자를 사용하고 `finally` 블록에서 제거한다.

동시성 시나리오는 최초 상태 생성 경쟁, 다중 체크포인트, 동일·상충 `payload`, 상태 갱신 실패 시 롤백, 실제 SQL Server 교착 상태와 재시도를 포함한다.

## 데모

```powershell
.\demo.ps1
Start-Process .\demo-result.html
```

스크립트는 Docker Compose, HTTP API, SQL Server의 결과를 모아 `demo-result.html`을 생성한다. 보고서에는 비밀값과 HMAC 서명을 제외한 요청·응답, 현재 상태, 동시성 결과, Classic ASP 계약과 제한사항이 기록된다.

## Windows IIS와 Classic ASP

다음 스크립트는 관리자 권한으로 IIS 10, Classic ASP, Microsoft OLE DB Driver 19, localhost 전용 사이트와 조회 전용 SQL 로그인을 구성한다. Windows 선택 기능과 시스템 드라이버를 변경하므로 로컬 개발 환경에서만 실행해야 한다.

```powershell
$setup = (Resolve-Path .\scripts\setup-iis-classic-asp.ps1).Path
Start-Process powershell.exe -Verb RunAs -Wait -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$setup`""
```

기본 주소는 `http://127.0.0.1:8091`이다. 연결 문자열은 소스나 `.env`에 저장하지 않고 IIS 앱 풀 환경변수로 전달한다. Classic ASP 코드는 매개변수화된 ADO 쿼리와 조회 전용 SQL 계정을 사용한다.

이 경로에는 사용자 인증이 없다. 경계는 호출자의 네트워크 주소다. 설치 스크립트가 사이트를 `127.0.0.1`에만 바인딩하지만, 그 통제만 있으면 바인딩을 넓히는 순간 인증 없는 조회 엔드포인트가 된다. 그래서 `legacy/progress.asp`가 `REMOTE_ADDR`을 직접 검사해 `127.0.0.0/8`과 `::1`이 아닌 호출자를 403으로 거부한다. 쿼리 문자열을 읽거나 연결을 열기 전에 검사하며, 계약 테스트가 이 순서까지 확인한다.

`REMOTE_ADDR`은 TCP 상대 주소이므로 `X-Forwarded-For`와 달리 클라이언트가 지정할 수 없다. 대신 이 어댑터를 리버스 프록시 뒤에 두면 프록시가 상대가 되어 모든 전달 요청이 통과한다. 프록시 뒤에 두지 않는다.

## 문서

- [ARCHITECTURE.md](ARCHITECTURE.md): 컴포넌트 책임과 읽기·쓰기 경계
- [DECISIONS.md](DECISIONS.md): 주요 설계 결정과 절충 사항
- [MYSQL_PORTABILITY.md](MYSQL_PORTABILITY.md): MySQL 이식 시 달라지는 동작
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md): 해결한 장애와 재발 방지 기록

운영 사용자 인증, MySQL 런타임, 부하·용량 시험은 제공 범위에 포함되지 않는다. Classic ASP 런타임은 Windows IIS 설정 스크립트로 별도 구성하며, Compose 테스트는 해당 소스와 JSON 계약을 확인한다.

## 라이선스

애플리케이션 코드는 [MIT License](LICENSE)로 배포한다. `public/swagger-ui`의 Swagger UI 정적 자산은 [Apache License 2.0](public/swagger-ui/LICENSE)을 따르며, 해당 디렉터리에 [NOTICE](public/swagger-ui/NOTICE)를 포함한다.
