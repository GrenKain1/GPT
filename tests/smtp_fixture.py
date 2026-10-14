"""A loopback-only, verified STARTTLS SMTP fixture for integration tests."""
from __future__ import annotations

import socket
import ssl
import threading
from contextlib import ExitStack
from pathlib import Path


class SMTPFixture:
    def __init__(self, certificate: Path, key: Path):
        self.context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        self.context.load_cert_chain(str(certificate), str(key))
        self.listener = socket.socket()
        self.listener.bind(("127.0.0.1", 0))
        self.listener.listen(5)
        self.listener.settimeout(0.2)
        self.port = self.listener.getsockname()[1]
        self.messages: list[bytes] = []
        self.errors: list[str] = []
        self.running = True
        self.fail_delivery = False
        self.thread = threading.Thread(target=self._serve, daemon=True)
        self.thread.start()

    def _serve(self):
        while self.running:
            try:
                connection, _ = self.listener.accept()
            except socket.timeout:
                continue
            except OSError:
                return
            try:
                self._client(connection)
            except (OSError, ssl.SSLError, ValueError) as error:
                if self.running:
                    self.errors.append(type(error).__name__)
            finally:
                connection.close()

    def _client(self, connection):
        with ExitStack() as cleanup:
            self._conversation(connection, cleanup)

    def _conversation(self, connection, cleanup):
        cleanup.callback(connection.close)
        connection.settimeout(5)
        stream = connection.makefile("rwb", buffering=0)
        cleanup.callback(stream.close)
        stream.write(b"220 localhost SMTP test fixture\r\n")
        tls = False
        while True:
            line = stream.readline()
            if not line:
                return
            command = line.decode("ascii").strip()
            upper = command.upper()
            if upper.startswith("EHLO"):
                stream.write(b"250-localhost\r\n")
                if not tls:
                    stream.write(b"250-STARTTLS\r\n")
                stream.write(b"250 AUTH LOGIN PLAIN\r\n")
            elif upper == "STARTTLS":
                stream.write(b"220 Ready to start TLS\r\n")
                stream.close()
                connection = self.context.wrap_socket(connection, server_side=True)
                cleanup.callback(connection.close)
                stream = connection.makefile("rwb", buffering=0)
                cleanup.callback(stream.close)
                tls = True
            elif upper.startswith("AUTH LOGIN"):
                if not tls:
                    stream.write(b"530 STARTTLS required\r\n")
                    continue
                stream.write(b"334 VXNlcm5hbWU6\r\n")
                stream.readline()
                stream.write(b"334 UGFzc3dvcmQ6\r\n")
                stream.readline()
                stream.write(b"235 Authentication successful\r\n")
            elif upper.startswith("AUTH PLAIN"):
                stream.write(b"235 Authentication successful\r\n" if tls else b"530 STARTTLS required\r\n")
            elif upper.startswith("MAIL FROM:") or upper.startswith("RCPT TO:"):
                stream.write(b"250 Accepted\r\n" if tls else b"530 STARTTLS required\r\n")
            elif upper == "DATA":
                stream.write(b"354 End with <CRLF>.<CRLF>\r\n")
                lines = []
                while True:
                    body_line = stream.readline()
                    if body_line == b".\r\n":
                        break
                    if not body_line:
                        return
                    lines.append(body_line[1:] if body_line.startswith(b"..") else body_line)
                if self.fail_delivery:
                    stream.write(b"550 Test delivery rejection\r\n")
                else:
                    self.messages.append(b"".join(lines))
                    stream.write(b"250 Message accepted\r\n")
            elif upper == "QUIT":
                stream.write(b"221 Bye\r\n")
                stream.close()
                connection.close()
                return
            else:
                stream.write(b"250 OK\r\n")

    def close(self):
        self.running = False
        self.listener.close()
        self.thread.join(timeout=2)
