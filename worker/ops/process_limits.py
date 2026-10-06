"""OS-level memory cap for the runner process (M5 security gate).

DuckDB's memory_limit only governs the engine's buffer manager; Python objects built from results are not counted.
The runner therefore caps its whole process:
  - Windows: assigns itself to a Job Object with JOB_OBJECT_LIMIT_PROCESS_MEMORY (allocations beyond fail);
  - POSIX: RLIMIT_AS.
Also exposes the peak memory actually used, reported in every runner response (stats.peak_memory_mb).
"""
from __future__ import annotations

import ctypes
import sys

_JOB_HANDLE = None  # keep the job object alive for the life of the process


def apply_memory_cap(limit_mb: int) -> bool:
    """Caps this process at ``limit_mb`` MiB of committed memory. Returns True when a cap is in place."""
    if limit_mb <= 0:
        return False
    if sys.platform == "win32":
        return _windows_job_cap(limit_mb * 1024 * 1024)
    try:
        import resource

        limit = limit_mb * 1024 * 1024
        resource.setrlimit(resource.RLIMIT_AS, (limit, limit))
        return True
    except (ImportError, ValueError, OSError):
        return False


def peak_memory_mb() -> int:
    if sys.platform == "win32":
        class PROCESS_MEMORY_COUNTERS(ctypes.Structure):  # noqa: N801 - Win32 name
            _fields_ = [
                ("cb", ctypes.c_ulong), ("PageFaultCount", ctypes.c_ulong),
                ("PeakWorkingSetSize", ctypes.c_size_t), ("WorkingSetSize", ctypes.c_size_t),
                ("QuotaPeakPagedPoolUsage", ctypes.c_size_t), ("QuotaPagedPoolUsage", ctypes.c_size_t),
                ("QuotaPeakNonPagedPoolUsage", ctypes.c_size_t), ("QuotaNonPagedPoolUsage", ctypes.c_size_t),
                ("PagefileUsage", ctypes.c_size_t), ("PeakPagefileUsage", ctypes.c_size_t),
            ]
        counters = PROCESS_MEMORY_COUNTERS()
        counters.cb = ctypes.sizeof(counters)
        psapi = ctypes.WinDLL("psapi")
        kernel32 = ctypes.WinDLL("kernel32")
        kernel32.GetCurrentProcess.restype = ctypes.c_void_p
        psapi.GetProcessMemoryInfo.argtypes = [ctypes.c_void_p, ctypes.c_void_p, ctypes.c_ulong]
        if psapi.GetProcessMemoryInfo(kernel32.GetCurrentProcess(), ctypes.byref(counters), counters.cb):
            return int(counters.PeakPagefileUsage // (1024 * 1024))
        return -1
    try:
        import resource

        return int(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss // 1024)  # KiB on Linux
    except ImportError:
        return -1


def _windows_job_cap(limit_bytes: int) -> bool:
    global _JOB_HANDLE

    class IO_COUNTERS(ctypes.Structure):  # noqa: N801
        _fields_ = [(n, ctypes.c_ulonglong) for n in (
            "ReadOperationCount", "WriteOperationCount", "OtherOperationCount",
            "ReadTransferCount", "WriteTransferCount", "OtherTransferCount")]

    class JOBOBJECT_BASIC_LIMIT_INFORMATION(ctypes.Structure):  # noqa: N801
        _fields_ = [
            ("PerProcessUserTimeLimit", ctypes.c_longlong), ("PerJobUserTimeLimit", ctypes.c_longlong),
            ("LimitFlags", ctypes.c_ulong), ("MinimumWorkingSetSize", ctypes.c_size_t),
            ("MaximumWorkingSetSize", ctypes.c_size_t), ("ActiveProcessLimit", ctypes.c_ulong),
            ("Affinity", ctypes.c_size_t), ("PriorityClass", ctypes.c_ulong), ("SchedulingClass", ctypes.c_ulong),
        ]

    class JOBOBJECT_EXTENDED_LIMIT_INFORMATION(ctypes.Structure):  # noqa: N801
        _fields_ = [
            ("BasicLimitInformation", JOBOBJECT_BASIC_LIMIT_INFORMATION), ("IoInfo", IO_COUNTERS),
            ("ProcessMemoryLimit", ctypes.c_size_t), ("JobMemoryLimit", ctypes.c_size_t),
            ("PeakProcessMemoryUsed", ctypes.c_size_t), ("PeakJobMemoryUsed", ctypes.c_size_t),
        ]

    job_object_limit_process_memory = 0x00000100
    job_object_extended_limit_information = 9
    kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)
    kernel32.CreateJobObjectW.restype = ctypes.c_void_p
    kernel32.CreateJobObjectW.argtypes = [ctypes.c_void_p, ctypes.c_wchar_p]
    kernel32.SetInformationJobObject.argtypes = [ctypes.c_void_p, ctypes.c_int, ctypes.c_void_p, ctypes.c_ulong]
    kernel32.AssignProcessToJobObject.argtypes = [ctypes.c_void_p, ctypes.c_void_p]
    kernel32.GetCurrentProcess.restype = ctypes.c_void_p

    job = kernel32.CreateJobObjectW(None, None)
    if not job:
        return False
    info = JOBOBJECT_EXTENDED_LIMIT_INFORMATION()
    info.BasicLimitInformation.LimitFlags = job_object_limit_process_memory
    info.ProcessMemoryLimit = limit_bytes
    if not kernel32.SetInformationJobObject(job, job_object_extended_limit_information, ctypes.byref(info), ctypes.sizeof(info)):
        return False
    if not kernel32.AssignProcessToJobObject(job, kernel32.GetCurrentProcess()):
        return False
    _JOB_HANDLE = job
    return True
