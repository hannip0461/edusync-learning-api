# MySQL 이식 참고 사항

이 프로젝트는 MySQL에서 실행하거나 검증하지 않았다. 이식하기 전에 다음 차이를 해결하고 대상 환경에서 다시 테스트해야 한다.

- SQL Server의 `IDENTITY`와 `OUTPUT INSERTED...`는 MySQL의 `AUTO_INCREMENT` 및 별도의 생성 키 조회 방식으로 바꿔야 한다. 현재 `OUTPUT` SQL은 MySQL에서 사용할 수 없다.
- SQL Server의 중복 오류 2601/2627과 교착 상태 오류 1205는 MySQL의 중복 키 오류 1062, 교착 상태 오류 1213과 다르다. 재시도 오류 분류를 대상 드라이버에 맞게 다시 작성하고 검증해야 한다.
- SQL Server의 현재 상태 행 처리 방식, `UPDLOCK,HOLDLOCK`과 이름이 지정된 고유 인덱스 범위 잠금은 MySQL에 직접 대응하는 문법이 없다. InnoDB 격리 수준, next-key/gap lock과 명시적인 `SELECT ... FOR UPDATE` 전략을 기준으로 경쟁 조건을 별도 검토해야 한다.
- `datetime2(3)`과 `SYSUTCDATETIME()`은 UTC 기준의 `DATETIME(3)` 규칙으로 바꿔야 한다. 드라이버의 시간대 변환과 소수점 이하 정밀도는 추정하지 말고 직접 테스트해야 한다.
- SQL Server에서 이벤트를 먼저 저장하는 중복 처리 방식을 MySQL의 `INSERT ... ON DUPLICATE KEY UPDATE`로 단순 대체하면 안 된다. 이 구문은 필요한 해시 비교와 현재 상태 반영 순서를 흐릴 수 있으므로 이벤트 원장을 먼저 저장하는 판정 경계를 유지해야 한다.
