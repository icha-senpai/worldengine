"""Compress a closed log with XZ maximum compression and verify before deletion."""
import argparse
import lzma
import os
from pathlib import Path
import shutil


def matching(source: Path, archive: Path) -> bool:
    try:
        with source.open("rb") as original, lzma.open(archive, "rb") as compressed:
            while True:
                expected = original.read(1024 * 1024)
                actual = compressed.read(1024 * 1024)
                if actual != expected:
                    return False
                if not expected:
                    return True
    except (OSError, lzma.LZMAError, EOFError):
        return False


def compress(source: Path, directory: Path) -> None:
    source = source.resolve()
    directory = directory.resolve()
    if source.parent != directory or source.suffix != ".log":
        raise ValueError("Only closed .log files inside the archive directory may be compressed")
    # OS locks release after a crash, and prevent old/new runners compressing
    # the same closed log during a restart. The tiny lock files are reusable.
    with Path(str(source) + ".lock").open("a+b") as lock:
        lock.seek(0)
        if os.name == "nt":
            import msvcrt
            if not lock.read(1):
                lock.write(b"\0")
                lock.flush()
            lock.seek(0)
            try:
                msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, 1)
            except OSError:
                return
        else:
            import fcntl
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                return
        if not source.exists():
            return
        archive = Path(str(source) + ".xz")
        if archive.exists() and matching(source, archive):
            source.unlink()
            return
        partial = Path(str(archive) + ".tmp")
        with source.open("rb") as original, lzma.open(
            partial, "wb", format=lzma.FORMAT_XZ, check=lzma.CHECK_CRC64,
            preset=9 | lzma.PRESET_EXTREME,
        ) as compressed:
            shutil.copyfileobj(original, compressed, length=1024 * 1024)
        if not matching(source, partial):
            raise OSError("Compressed log verification failed; original retained")
        os.replace(partial, archive)
        # Delete only the closed source after verifying every decompressed byte.
        source.unlink()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source", type=Path)
    parser.add_argument("directory", type=Path)
    arguments = parser.parse_args()
    compress(arguments.source, arguments.directory)
