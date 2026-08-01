# 배포와 되돌리기 점검표

현재 저장소의 Docker Compose API, SQL Server, IIS Classic ASP 어댑터를 같은 버전으로 운영할 때 사용하는 점검표다. 환경 비밀값은 `.env`와 IIS 앱 풀 환경변수로만 전달한다.

## 배포 전

- [ ] 배포할 태그와 직전 정상 태그를 기록한다.
- [ ] SQL Server 백업과 복원 가능 여부를 확인한다.
- [ ] 배포 환경의 `.env`에 로컬 예제값이 아닌 별도 비밀값을 설정한다.
- [ ] `docker compose config --quiet`가 통과하는지 확인한다.
- [ ] GitHub Actions의 `실제 MSSQL 검증` 작업이 통과했는지 확인한다.
- [ ] 릴리스의 `demo-result.html`에서 16개 시나리오가 모두 PASS인지 확인한다.

## Docker Compose 배포

```powershell
docker compose config --quiet
docker compose build
docker compose up -d
docker compose ps
Invoke-RestMethod http://127.0.0.1:8080/health
```

`/health` 응답의 `database`가 `connected`, `probe`가 `1`인지 확인한다. 이어서 `/docs`, `/openapi.yaml`, 실제 인증을 사용하는 쓰기 요청 한 건과 보호자 조회를 점검한다.

## IIS 어댑터

- [ ] IIS 사이트의 실제 경로가 현재 릴리스의 `legacy` 디렉터리인지 확인한다.
- [ ] 사이트 바인딩이 `127.0.0.1`로 제한됐는지 확인한다.
- [ ] 앱 풀 환경변수와 조회 전용 SQL 로그인의 SELECT 전용 권한을 확인한다.
- [ ] 정상 조회 200, 잘못된 입력 400, 없는 진행 상태 404를 확인한다.
- [ ] 루프백 밖 요청이 허용되지 않는지 확인한다.

## 배포 후

- [ ] 컨테이너가 재시작 없이 유지되는지 확인한다.
- [ ] 데이터베이스 마이그레이션 컨테이너가 종료 코드 0인지 확인한다.
- [ ] GitHub Pages의 API 문서와 실행 검증 결과가 열리는지 확인한다.
- [ ] 애플리케이션 로그에 토큰, 서명, SQL, 직접 식별자가 남지 않았는지 확인한다.

## 되돌리기

1. 장애 시 새 쓰기 트래픽을 중지하고 발생 시각과 배포 태그를 기록한다.
2. 직전 정상 태그의 코드와 컨테이너 이미지를 배포한다.
3. 해당 버전의 `docker compose up -d`를 실행한다.
4. `/health`, 쓰기 멱등 처리, 보호자 조회를 다시 확인한다.
5. IIS 경로를 바꿨다면 직전 정상 릴리스의 `legacy` 디렉터리로 되돌리고 200, 400, 404 계약을 확인한다.

스키마는 자동으로 하향 변경하지 않는다. 현재 버전은 `001_init.sql`만 사용하지만, 이후 마이그레이션이 추가되면 배포 전에 버전별 복원 절차를 함께 준비한다.
