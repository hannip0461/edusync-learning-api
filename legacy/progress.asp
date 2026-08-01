<%@ Language=VBScript CodePage=65001 %>
<%
Option Explicit

Const adCmdText = 1
Const adParamInput = 1
Const adBigInt = 20

Response.CodePage = 65001
Response.Charset = "utf-8"
Response.ContentType = "application/json"

' 사용자 인증이 없으므로 루프백 호출만 허용한다(DECISIONS D6).
' REMOTE_ADDR가 없을 때도 거부되도록 빈 문자열로 정규화한다.
Dim remoteAddress
remoteAddress = "" & Request.ServerVariables("REMOTE_ADDR")
If Left(remoteAddress, 4) <> "127." And remoteAddress <> "::1" Then
    Response.Status = "403 Forbidden"
    Response.Write "{""error"":""this adapter accepts loopback clients only""}"
    Response.End
End If

Dim learnerId, lectureId
learnerId = Request.QueryString("learner_id")
lectureId = Request.QueryString("lecture_id")

If Not IsInt64Text(learnerId) Or Not IsInt64Text(lectureId) Then
    Response.Status = "400 Bad Request"
    Response.Write "{""error"":""learner_id and lecture_id must be int64 decimal integers""}"
    Response.End
End If

Dim connection, command, recordset
Set connection = Server.CreateObject("ADODB.Connection")
' Configure this IIS Application value outside source control. Do not hard-code credentials.
connection.Open Application("EduSyncConnectionString")

Set command = Server.CreateObject("ADODB.Command")
Set command.ActiveConnection = connection
command.CommandType = adCmdText
command.CommandText = _
    "SELECT learner_id, lecture_id, resume_position_seconds, furthest_position_seconds, " & _
    "last_studied_at, completed_at " & _
    "FROM dbo.lecture_progress " & _
    "WHERE learner_id = ? AND lecture_id = ?"
' Preserve the numeric text so ADO can bind the full BIGINT range without 32-bit CLng.
command.Parameters.Append command.CreateParameter("@learner_id", adBigInt, adParamInput, , learnerId)
command.Parameters.Append command.CreateParameter("@lecture_id", adBigInt, adParamInput, , lectureId)

Set recordset = command.Execute()

If recordset.EOF Then
    Response.Status = "404 Not Found"
    Response.Write "{""error"":""progress not found""}"
Else
    Response.Write "{" & _
        """learner_id"":" & CStr(recordset("learner_id")) & "," & _
        """lecture_id"":" & CStr(recordset("lecture_id")) & "," & _
        """resume_position_seconds"":" & CStr(recordset("resume_position_seconds")) & "," & _
        """furthest_position_seconds"":" & CStr(recordset("furthest_position_seconds")) & "," & _
        """last_studied_at"":" & JsonDate(recordset("last_studied_at")) & "," & _
        """completed_at"":" & JsonDate(recordset("completed_at")) & _
        "}"
End If

recordset.Close
connection.Close
Set recordset = Nothing
Set command = Nothing
Set connection = Nothing

Function IsInt64Text(value)
    Dim digits, limit, index, character, firstNonZero
    IsInt64Text = False

    If Len(value) = 0 Then Exit Function

    digits = value
    limit = "9223372036854775807"
    If Left(digits, 1) = "-" Then
        digits = Mid(digits, 2)
        limit = "9223372036854775808"
    End If

    If Len(digits) = 0 Then Exit Function

    For index = 1 To Len(digits)
        character = Mid(digits, index, 1)
        If InStr(1, "0123456789", character, 0) = 0 Then Exit Function
    Next

    firstNonZero = 1
    Do While firstNonZero < Len(digits) And Mid(digits, firstNonZero, 1) = "0"
        firstNonZero = firstNonZero + 1
    Loop
    digits = Mid(digits, firstNonZero)

    If Len(digits) > Len(limit) Then Exit Function
    If Len(digits) = Len(limit) And StrComp(digits, limit, 0) > 0 Then Exit Function

    IsInt64Text = True
End Function

Function JsonDate(value)
    If IsNull(value) Then
        JsonDate = "null"
    Else
        JsonDate = """" & Year(value) & "-" & Right("0" & Month(value), 2) & "-" & Right("0" & Day(value), 2) & _
            "T" & Right("0" & Hour(value), 2) & ":" & Right("0" & Minute(value), 2) & ":" & Right("0" & Second(value), 2) & ".000Z"""
    End If
End Function
%>
