# Claude Repository
A repository used for use with Claude Code while learning to use this AI resource.

Branches are created by Claude Code for each coding project.  This may not work for serious coding projects.

**To use this for multiple projects, do not merge branches with main.**

---
## AWS Reserved Instances Expiry Monitor

A Python script that checks all EC2 Reserved Instances across one or more AWS regions and sends an email alert when any RI will expire within a configurable window (default: 30 days).

Code for this project is in the branch **claude/aws-reserved-instances-monitor-DmFzK**

---
## XLSX to ODS Converter

A dependency-free Python script (`xlsx_to_ods.py`) that converts an .xlsx workbook to .ods, auto-detecting which columns hold dates, currency amounts, or plain numbers (even when the source stores everything as text) and writing real typed, formatted cells in the output. Uses only the standard library.

```
python3 xlsx_to_ods.py input.xlsx [-o output.ods]
```

Code for this project is in the branch **claude/xlsx-ods-conversion-1avulr**

---
