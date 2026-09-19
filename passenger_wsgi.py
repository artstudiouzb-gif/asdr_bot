"""Точка входа для cPanel/Plesk (Passenger). В настройках Python App укажите
Application startup file = passenger_wsgi.py, Application Entry point = application.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from asdr.web import application  # noqa: E402,F401
