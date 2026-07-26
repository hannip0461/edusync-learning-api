# Troubleshooting

이 문서는 해결한 장애를 PAAR(Problem, Analysis, Action, Result) 형식으로 기록한다.

## 2026-07-26 — Docker 엔진 미기동

상태: 해결

### P — Problem

`docker compose` 구성 확인, 이미지 빌드, MSSQL 기동과 통합 테스트를 실행할 수 없었다.

### A — Analysis

Compose 파일이나 애플리케이션 오류가 아니라 Windows에서 Docker 엔진이 실행되지 않은 환경 문제였다. 이 프로젝트의 PHP 확장, SQL Server, 통합·동시성 테스트는 Linux 컨테이너 실행을 전제로 한다.

### A — Action

- Docker Desktop과 Linux 컨테이너 엔진을 기동했다.
- `docker compose config --quiet`으로 `.env` 치환과 Compose 문법을 먼저 확인했다.
- DB 컨테이너의 상태 검사가 끝난 뒤 마이그레이션과 `app` 컨테이너를 순서대로 실행했다.

### R — Result

- MSSQL 컨테이너 `healthy`
- `/health`: `database=connected`, `driver=pdo_sqlsrv`, `probe=1`
- 이후 계약·통합·동시성 테스트 실행 가능

## 2026-07-26 — 인증 환경변수 누락

상태: 해결

### P — Problem

인증 기능이 추가된 뒤 기존 로컬 `.env`만으로는 Compose 구성을 완성할 수 없었다. `docker-compose.yml`의 필수 변수 표현식이 Bearer/HMAC 관련 값의 누락을 즉시 거부했다.

### A — Analysis

인증 토큰과 HMAC 비밀값에 코드 기본값을 두면 잘못된 설정으로도 서비스가 기동될 수 있다. 필수값을 Compose에서 강제하는 설계는 맞았고, 로컬 전용 설정만 보완하면 됐다.

### A — Action

Git에서 제외된 `.env`에 다음 키를 로컬 값으로 설정했다. 값 자체는 소스·문서·로그에 기록하지 않았다.

- `APP_BEARER_TOKEN`, `APP_BEARER_LEARNER_ID`, `APP_EVENT_SOURCE`
- `GUARDIAN_BEARER_TOKEN`, `GUARDIAN_BEARER_ID`
- `PLAYER_HMAC_SECRET`, `PLAYER_EVENT_SOURCE`, `HMAC_TOLERANCE_SECONDS`

### R — Result

- 임시 세션 환경변수 없이 `docker compose config --quiet` 통과
- 시드 데이터의 학습자·보호자 기준으로 `app` 컨테이너 재생성
- DB 상태 검사, 마이그레이션, `/health` 확인 완료
- 추적 파일의 비밀값 변경 없음

## 2026-07-26 — 미등록 경로와 잘못된 메서드가 HTTP 500 반환

상태: 해결

### P — Problem

HTTP 요청에서 다음 경로가 모두 500으로 처리됐다.

- 미등록 경로: 기대 404
- 등록된 경로의 허용되지 않은 메서드: 기대 405

### A — Analysis

`ApiErrorMiddleware`가 애플리케이션의 `ApiException`만 구분하고, Slim의 `HttpNotFoundException`과 `HttpMethodNotAllowedException`은 일반 `Throwable` 경로로 보내고 있었다. 이 때문에 클라이언트 오류가 내부 서버 오류로 변환됐다.

### A — Action

- Slim `HttpException`을 별도로 처리해 원래 404/405 상태를 JSON 오류 응답에 보존했다.
- 405에서는 Slim이 계산한 허용 메서드를 `Allow` 헤더로 전달했다.
- 그 밖의 예외는 세부 정보를 노출하지 않는 500 처리를 유지했다.
- 미등록 경로와 잘못된 메서드 회귀 테스트를 추가했다.

### R — Result

- 미등록 경로: JSON 404
- 잘못된 메서드: JSON 405와 `Allow` 헤더
- `correlation_id`와 공통 `ErrorResponse` 형식 유지
- 전체 HTTP+MSSQL 통합 테스트 통과

## 2026-07-26 — 런타임·OpenAPI·Composer 메타데이터 드리프트

상태: 해결

### P — Problem

- `EventInput`은 `position_seconds`를 SQL Server `int` 최댓값인 `2147483647`로 제한했지만 OpenAPI에는 상한이 없었다.
- Composer 패키지 이름과 설명이 공개 프로젝트 명칭 및 현재 기능과 일치하지 않았고, `composer.json` 변경 뒤 `composer.lock`의 `content-hash`도 동기화가 필요했다.
- OpenAPI 린트는 Classic ASP 경로의 4XX 응답 누락도 경고했다.

### A — Analysis

코드, API 문서, 패키지 메타데이터를 따로 수정하면서 동일한 계약을 설명하는 파일들이 어긋났다. 런타임은 요청을 거부하는데 문서는 허용한다고 표시하면 클라이언트 생성과 검증 결과가 달라진다.

### A — Action

- `LearningEventInput.position_seconds`에 `maximum: 2147483647`을 추가했다.
- Classic ASP 경로에 400, 404, 500 응답 계약을 추가했다.
- `composer.json`의 패키지 이름과 설명을 공개 프로젝트 명칭에 맞추고 `composer.lock`의 `content-hash`를 재생성했다.
- MIT 라이선스 메타데이터와 표준 라이선스 파일을 추가했다.
- Composer 검증과 Redocly 린트를 함께 실행했다.

### R — Result

- 런타임과 OpenAPI의 `position_seconds` 범위 일치
- `composer validate --no-check-publish` 경고 없이 통과
- `composer check-platform-reqs` 통과
- Redocly 오류 0, 개발용 localhost와 `/health`·`/docs`의 4XX 응답에 관한 권장 경고 3개만 유지

## 2026-07-26 — 핵심 진행 규칙의 회귀 테스트 공백

상태: 해결

### P — Problem

구현과 수동 확인은 정상이었지만 다음 규칙을 HTTP+MSSQL 통합 테스트가 고정하지 못했다.

- 서로 다른 `session_id` 간 `resume_position_seconds` 최신성
- 수강 기간 밖 `occurred_at`의 422
- 두 번째 `COMPLETED`가 최초 `completed_at`을 바꾸지 않는 규칙

### A — Analysis

테스트가 없으면 정렬 조건이나 `CASE` 식이 바뀌어도 회귀를 놓칠 수 있었다. 핵심 규칙을 고정할 통합 테스트가 부족했다.

### A — Action

- `session_id`가 다른 최신·과거 이벤트를 모두 저장하고 `resume_position_seconds`와 `furthest_position_seconds`를 각각 검증했다.
- 수강 시작 전과 종료 후 이벤트의 422를 추가했다.
- CHECKPOINT 이후에도, 두 번째 COMPLETED 이후에도 최초 `completed_at`이 유지되는지 확인했다.
- 모든 테스트 데이터는 고유 ID를 사용하고 `finally` 블록에서 제거했다.

### R — Result

- 서로 다른 `session_id`: `(occurred_at, received_at, event_seq)` 순서 회귀 방지
- 수강 기간: 시작 전·종료 후 모두 422
- `completed_at`: 최초 시각 불변
- 테스트 데이터 잔존 없음, 통합 테스트 통과

## 2026-07-26 — 시간 운에 의존하는 동시성 검증

상태: 해결

### P — Problem

순차 요청이나 고정 대기만으로는 최초 `lecture_progress` 생성 경쟁, 중복·상충 이벤트, 원자적 롤백, 실제 교착 상태 재시도를 증명할 수 없었다. 요청이 실제로 겹쳤는지도 보장하기 어려웠다.

### A — Analysis

참여 프로세스가 같은 지점에서 대기한 뒤 동시에 HTTP 요청을 시작하도록 DB 배리어가 필요했다. 교착 상태 역시 반대 순서의 잠금과 롤백 흔적을 확인해야 했다.

### A — Action

- `proc_open` 기반 별도 HTTP 프로세스와 DB 배리어 테이블을 사용했다.
- T01/T02/T03/T04로 최초 생성, 12개 체크포인트, 동일 `payload` 중복, 상충 `payload`를 검증했다.
- T08은 상태 갱신 트리거 실패 시 이벤트와 현재 상태가 함께 롤백되는지 확인했다.
- T12는 `left`·`right` 잠금 테이블과 역순 `UPDATE`로 실제 교착 상태를 만들었다.
- `IDENT_CURRENT = before + 2`로 롤백된 INSERT 한 번과 재시도 INSERT 한 번을 확인했다.
- 실행별 고유 접두사를 사용하고 `finally` 블록에서 테스트 데이터를 제거했다.

### R — Result

- T01/T02/T03/T04/T08/T12 모두 통과
- 중복 경쟁: `applied` 1, `duplicate` 5, 저장 이벤트 1
- 상충 `payload` 경쟁: 응답 200·409, 저장 이벤트 1
- 상태 갱신 실패: 이벤트 0, 진행 상태 0
- 교착 상태: 최종 200, 커밋된 이벤트·현재 상태 각 1, 롤백·재시도 근거 확인
- 테스트 전용 테이블·트리거 잔존 없음

## 2026-07-26 — PDO_SQLSRV 트리거 결과셋과 예외 전달

상태: 해결

### P — Problem

`lecture_progress` INSERT/UPDATE에 연결한 테스트 트리거의 실패나 교착 상태가 PDO_SQLSRV의 뒤쪽 결과셋에 남으면 트랜잭션 경계까지 예외가 안정적으로 전달되지 않을 수 있었다. 반대로 모든 문장에 같은 소비 로직을 적용하면 `learning_events` INSERT의 `OUTPUT INSERTED...` 행까지 잃게 된다.

### A — Analysis

`learning_events` INSERT는 반환된 `event_seq`와 `received_at`을 읽어야 한다. `lecture_progress` DML은 반환 행이 필요 없지만 트리거가 만든 추가 결과셋을 끝까지 진행한 뒤 `errorInfo`를 확인해야 한다. 두 실행 경로의 요구가 달랐다.

### A — Action

- `learning_events` INSERT는 `fetch()`로 OUTPUT 행을 읽고 `closeCursor()`를 호출했다.
- `lecture_progress` INSERT/UPDATE에만 `executeAndConsume()`을 적용했다.
- `nextRowset()`을 끝까지 수행한 뒤 `errorInfo`를 검사해 PDOException을 만들었다.
- 예외는 `ProgressService`의 롤백 및 1205/3960 재시도 경계로 전달했다.

### R — Result

- 이벤트의 `event_seq`·`received_at` 정상 보존
- T08 트리거의 `THROW`가 HTTP 500과 전체 롤백으로 관찰됨
- T12 교착 상태 오류 1205가 전체 트랜잭션 1회 재시도로 연결됨
- OUTPUT 행 손실 없이 트리거 오류 전달 검증 통과

## 2026-07-26 — Windows IIS·Classic ASP 실행 전제조건 부재

상태: 해결

### P — Problem

초기 Windows 환경에는 W3SVC, WAS, AppCmd, `asp.dll`, IIS Express가 없어 `legacy/progress.asp`를 실제로 실행할 수 없었다. 비관리자 DISM 조회와 기능 활성화는 종료 코드 740으로 거부됐고, 최신 SQL Server OLE DB 공급자도 등록되지 않았다.

### A — Analysis

소스 계약 테스트는 ASP 문법과 JSON 형태만 확인할 뿐 IIS 핸들러, Windows 계정, ADO 공급자, SQL 연결을 검증하지 못한다. Windows 선택 기능 활성화와 시스템 드라이버 설치는 관리자 권한이 필요했다.

### A — Action

- UAC로 상승한 Windows PowerShell에서 IIS, Classic ASP, ISAPI Extensions와 관리 도구를 활성화했다.
- Microsoft 서명과 고정 SHA-256을 확인한 OLE DB Driver 19.4.2 x64를 설치했다.
- `EduSyncLegacyAspPool`과 `127.0.0.1:8091` 전용 사이트를 만들었다.
- `lecture_progress` SELECT만 허용하고 DML을 거부한 `edusync_legacy_reader`를 생성했다.
- 연결 문자열은 소스나 `.env`가 아니라 앱 풀 환경변수로 전달했다.

### R — Result

- IIS 10, W3SVC, WAS, AppCmd, `asp.dll`, MSOLEDBSQL19 확인
- 재부팅 불필요
- ADO 연결 및 실제 `progress.asp` HTTP 200 확인
- SQL 권한: CONNECT/SELECT GRANT, INSERT/UPDATE/DELETE DENY

## 2026-07-26 — Windows PowerShell 5.1의 UTF-8 no-BOM 오해석

상태: 해결

### P — Problem

설정 스크립트의 한글 상태·오류 문구가 Windows PowerShell 5.1에서 깨진 문자로 출력됐다.

### A — Analysis

프로젝트 파일은 UTF-8 no-BOM/LF였지만 Windows PowerShell 5.1은 BOM 없는 스크립트를 시스템 ANSI 코드 페이지로 해석했다. 파일 자체가 손상된 것은 아니며 PowerShell 7에서는 재현되지 않았다.

### A — Action

- 스크립트의 실행 로그와 예외 문구를 ASCII 영문으로 변경했다.
- 프로젝트의 UTF-8 no-BOM/LF 형식은 유지했다.
- PowerShell 5.1 파서로 문법을 별도 확인했다.

### R — Result

- 관리자·비관리자 실행 모두 메시지 손상 없음
- 대체 문자(U+FFFD) 없음
- 불필요한 BOM 추가나 전체 파일 인코딩 변환 없음

## 2026-07-26 — PowerShell 파이프의 BOM이 sqlcmd 입력에 포함됨

상태: 해결

### P — Problem

PowerShell 문자열을 `docker compose exec ... sqlcmd`의 표준입력으로 파이프했을 때 SQL Server가 첫 문자에서 `Incorrect syntax near '﻿'`를 반환했다.

### A — Analysis

쿼리 앞에 UTF-8 BOM이 삽입돼 `SET` 앞의 보이지 않는 문자를 SQL 토큰으로 해석했다. SQL 문장이나 DB 상태 문제가 아니었다.

### A — Action

- PowerShell 파이프라인 대신 `System.Diagnostics.ProcessStartInfo`를 사용했다.
- `RedirectStandardInput`으로 BOM 없는 표준입력을 Docker/sqlcmd에 전달했다.
- `stdout`, `stderr`, 종료 코드를 분리해 비밀값 없이 결과를 판정했다.

### R — Result

- `SELECT 1` 준비 상태 확인 완료
- 최소 권한 SQL 로그인·사용자 생성 완료
- 비밀번호를 명령행이나 로그에 출력하지 않고 재실행 가능

## 2026-07-26 — IIS 앱 풀 환경변수 관리 API 호환성

상태: 해결

### P — Problem

앱 풀 환경변수를 설정하는 과정에서 두 오류가 연속으로 발생했다.

1. `Microsoft.ApplicationHost.WritableAdminManager` COM 객체가 null이어서 `CommitPath` 설정 시 null 참조 오류 발생
2. 새 컬렉션 요소를 추가할 때 필수 `value`가 없어 구성 저장 거부

### A — Analysis

COM 관리 객체 생성은 해당 환경에서 안정적이지 않았다. IIS 구성 컬렉션은 `Add()` 시점에 필수 속성을 즉시 검증하므로 `name`만 지정하고 나중에 `value`를 넣을 수 없었다.

### A — Action

- COM 경로를 `Microsoft.Web.Administration.ServerManager` .NET API로 교체했다.
- 기존 변수는 `value`만 갱신하고, 새 변수는 `name`과 `value`를 모두 설정한 뒤 컬렉션에 추가했다.
- `CommitChanges()` 이후 앱 풀을 재순환했다.

### R — Result

- `EDUSYNC_LEGACY_CONNECTION_STRING`이 지정 앱 풀에 정상 저장됨
- 재실행 시 중복 요소 없이 값 갱신
- 소스·`.env`에 DB 비밀번호 추가 없음

## 2026-07-26 — IIS 익명 인증 계정과 폴더 ACL 불일치

상태: 해결

### P — Problem

사이트와 DB 설정을 마친 뒤 실제 ASP 요청이 HTTP 401을 반환했다.

### A — Analysis

사이트의 익명 인증은 기본값인 `IUSR`을 사용했지만, `legacy` 디렉터리의 읽기/실행 권한은 `IIS AppPool\EduSyncLegacyAspPool`에 부여돼 있었다. 요청 실행 계정과 ACL 주체가 달랐다.

### A — Action

- 사이트별 `anonymousAuthentication.userName`을 빈 문자열로 설정했다.
- 익명 요청이 앱 풀 ID로 실행되도록 통일했다.
- 앱 풀 가상 계정에 디렉터리 `ReadAndExecute` 상속 권한을 설정했다.

### R — Result

- HTTP 401 해소
- 별도 로컬 사용자나 평문 Windows 비밀번호 불필요
- localhost의 ASP 요청이 DB 연결 단계까지 진행

## 2026-07-26 — 상세 오류 진단 설정 복구가 원래 오류를 가림

상태: 해결

### P — Problem

Classic ASP 500 원인을 확인하기 위해 상세 오류를 일시 활성화했지만, 복구 단계에서 `Value` 속성을 찾지 못하는 오류가 발생해 원래 ASP 오류가 가려졌다.

### A — Analysis

`Get-WebConfigurationProperty` 반환값을 항상 `.Value`를 가진 객체라고 가정했지만 Windows PowerShell/WebAdministration 조합에서는 스칼라로 반환됐다. `finally` 내부의 복구 오류가 앞선 `WebException`보다 나중에 발생해 최종 오류가 바뀌었다.

### A — Action

- 진단 중에만 `scriptErrorSentToBrowser=true`, `existingResponse=PassThrough`를 설정했다.
- `finally`에서는 알려진 안전 기본값인 `false`와 `Auto`를 직접 복구했다.
- 응답 본문은 HTML 태그 제거와 길이 제한 후 진단에만 사용했다.

### R — Result

- 원래 Classic ASP 500 원인 식별 가능
- 성공·실패와 관계없이 상세 오류 설정 원상복구
- 외부 응답은 다시 일반화된 500 메시지만 제공

## 2026-07-26 — Classic ASP int64 입력 검증

상태: 해결

### P — Problem

Windows IIS에서 `legacy/progress.asp`를 처음 실행했을 때 정상 식별자도 HTTP 500을 반환했다. `CDec`를 제거해 정상 조회를 복구한 뒤 경계값을 추가 확인하자 다음 문제도 드러났다.

- `IsNumeric`이 `1.5`, `1e3` 같은 int64가 아닌 표현을 허용해 DB 조회까지 전달했다.
- `9223372036854775808`처럼 signed BIGINT 범위를 벗어난 값은 ADO 변환 중 HTTP 500이 됐다.
- 소스 계약 테스트는 실제 VBScript 런타임에 없는 `CDec` 사용을 오히려 요구하고 있었다.

### A — Analysis

- OpenAPI 계약은 `type: integer`, `format: int64`이므로 입력은 부호가 선택적인 10진 정수이며 범위는 `-9223372036854775808`부터 `9223372036854775807`까지다.
- Classic ASP의 VBScript에서는 `CDec`를 사용할 수 없었다. `CLng`은 32-bit이고 `CDbl`은 큰 정수의 정밀도를 보존하지 못하므로 대체 변환으로 적합하지 않다.
- Microsoft OLE DB Driver 19의 `adBigInt` 매개변수는 검증된 10진 문자열을 직접 받아 전체 int64 범위를 정확히 바인딩했다.
- HTTP 경계에서 문자열 문법과 범위를 먼저 검증하고, 통과한 원문만 ADO에 넘기도록 했다.

### A — Action

- `IsNumeric`을 `IsInt64Text`로 교체했다.
- 선택적 `-`와 ASCII 숫자만 허용하고, 선행 0을 제외한 자릿수와 문자열 비교로 signed int64 상하한을 검사했다.
- 유효한 값은 변환하지 않고 `adBigInt` 매개변수에 문자열로 전달했다.
- 소수, 지수 표기, `+`, 빈 값, 범위 초과는 안정적인 JSON과 HTTP 400을 반환하게 했다.
- 계약 테스트에서 `IsNumeric`과 손실 가능 변환을 금지하고 양쪽 int64 경계 검사를 요구했다.
- OpenAPI의 Classic ASP 경로에 400, 404, 500 응답 계약을 추가했다.

### R — Result

실제 Windows IIS 10 + Classic ASP + Microsoft OLE DB Driver 19.4.2에서 다음을 확인했다.

| 입력 | 결과 |
| --- | --- |
| `2001`, `4001` | HTTP 200, 진행 상태 JSON |
| `0002001`, `0004001` | HTTP 200 |
| `9223372036854775807` | 유효한 int64, 미존재 HTTP 404 |
| `-9223372036854775808` | 유효한 int64, 미존재 HTTP 404 |
| `9223372036854775808` | HTTP 400 |
| `-9223372036854775809` | HTTP 400 |
| `1.5`, `1e3`, `+2001`, 빈 값 | HTTP 400 |

추가 검증 결과:

- `composer test`: Classic ASP 계약, MSSQL HTTP 통합, 동시성 T01/T02/T03/T04/T08/T12 모두 통과
- Redocly: OpenAPI 오류 0, 기존 권장 규칙 경고 4
- 수정 파일 UTF-8/LF, 대체 문자(U+FFFD) 없음

재발 방지 원칙: Classic ASP 런타임 동작을 바꾸면 소스 계약 테스트와 함께 IIS에서 정상값, 상하한, 상하한 초과, 비정수 표현을 확인한다.

## 2026-07-26 — IIS 기본 사이트의 불필요한 80번 포트 노출

상태: 해결

### P — Problem

IIS 기능 활성화 후 Windows가 자동 생성한 `Default Web Site`가 `*:80`에서 IIS 환영 페이지를 HTTP 200으로 제공했다. EduSync 사이트는 localhost 전용이었지만 별도의 기본 사이트가 외부 인터페이스에 열려 있었다.

### A — Analysis

Classic ASP 검증에는 기본 사이트가 필요하지 않다. 프로젝트 사이트만 `127.0.0.1:8091`에 바인딩해도 충분하므로 `*:80`은 불필요한 공격 표면이었다.

### A — Action

- 기본 `Default Web Site`를 중지했다.
- `serverAutoStart=false`로 재부팅 후 자동 재개를 막았다.
- EduSync 사이트의 localhost 바인딩은 유지했다.

### R — Result

- `http://127.0.0.1/`: 연결 닫힘
- `http://127.0.0.1:8091/...`: HTTP 200
- LAN 주소의 8091 요청은 프로젝트 콘텐츠를 제공하지 않음

## 2026-07-26 — 일회성 테스트 컨테이너의 localhost 오해

상태: 해결

### P — Problem

현재 소스를 읽기 전용으로 마운트한 일회성 `app` 컨테이너에서 `composer test`를 실행하자 계약 테스트는 통과했지만 `/docs` 요청이 `127.0.0.1:80 connection refused`로 실패했다.

### A — Analysis

통합 테스트의 `127.0.0.1`은 실행 중인 기존 `app` 컨테이너가 아니라 테스트 프로세스가 들어 있는 일회성 컨테이너 자신을 가리킨다. 그 컨테이너에서는 Apache가 시작되지 않은 상태였다.

### A — Action

- 소스의 읽기 전용 마운트는 유지했다.
- 일회성 컨테이너 안에서 `apache2-foreground`를 먼저 시작했다.
- `/health`가 준비될 때까지 제한 시간 동안 폴링한 뒤 `composer test`를 실행했다.
- Compose 이미지를 현재 소스로 다시 빌드하고 정식 `app` 컨테이너에서도 테스트했다.

### R — Result

- 계약, MSSQL HTTP 통합, 동시성 테스트 모두 통과
- 실행 중인 `app` 컨테이너와 현재 소스 일치
- `/health`: `connected`
- IIS Classic ASP 응답도 200/400/404 유지

## 2026-07-26 — Swagger UI 정적 자산과 라이선스 고지

상태: 해결

### P — Problem

로컬 `/docs`에 포함된 Swagger UI가 5.17.14에 머물러 있었고, Apache License 2.0으로 배포되는 정적 자산의 `LICENSE`와 `NOTICE`가 저장소에 포함되지 않았다.

### A — Analysis

CDN을 사용하지 않는 구성은 외부 장애에 영향을 받지 않지만, 저장소에 복사한 정적 자산은 자동으로 갱신되지 않는다. 또한 프로젝트의 MIT 라이선스는 제3자 Apache-2.0 자산의 원래 라이선스와 고지를 대체하지 않는다.

### A — Action

- 공식 npm registry의 `swagger-ui-dist` 5.32.6 패키지를 내려받았다.
- npm에 게시된 SHA-512 무결성 값과 내려받은 패키지를 비교했다.
- `swagger-ui-bundle.js`, `swagger-ui-standalone-preset.js`, CSS를 교체하고 각 JavaScript 라이선스 파일을 함께 추가했다.
- Swagger UI의 Apache-2.0 `LICENSE`와 `NOTICE`를 정적 자산 디렉터리에 포함했다.
- README에 버전과 제3자 라이선스 적용 범위를 명시했다.

### R — Result

- 공식 배포본과 저장소 정적 자산의 SHA-256 일치
- JavaScript 구문 검사 통과
- `/docs`와 CSS·JavaScript 자산 HTTP 200
- 실제 브라우저에서 API 제목, 6개 작업, Classic ASP 경로가 정상 표시되는지 확인
- 브라우저 콘솔 경고·오류 없음
- 전체 계약·통합·동시성 테스트 통과

## 2026-07-26 — Compose 개발 포트의 전체 인터페이스 노출

상태: 해결

### P — Problem

Compose 기본 포트 매핑으로 API 8080과 SQL Server 1433이 `0.0.0.0`과 IPv6 전체 인터페이스에서 대기하고 있었다. 로컬 개발 서비스가 같은 네트워크의 다른 장치에서도 접근 가능한 상태였다.

### A — Analysis

컨테이너 간 통신은 Compose 내부 네트워크를 사용하고, Windows IIS 연동도 `127.0.0.1:1433`만 필요하다. 호스트의 모든 인터페이스에 API와 데이터베이스 포트를 공개할 이유가 없었다.

### A — Action

- SQL Server 매핑을 `127.0.0.1:1433:1433`으로 제한했다.
- API 매핑을 `127.0.0.1:${APP_PORT:-8080}:80`으로 제한했다.
- 컨테이너를 재생성하고 실제 수신 주소와 API·IIS 응답을 확인했다.

### R — Result

- API와 SQL Server 포트가 IPv4 루프백 주소에서만 대기
- Docker 내부의 `app`→`db` 통신 정상
- `/health`와 전체 계약·통합·동시성 테스트 통과
- Windows IIS Classic ASP 조회 HTTP 200 유지
