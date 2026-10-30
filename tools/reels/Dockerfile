FROM python:3.12-slim
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg fonts-inter && rm -rf /var/lib/apt/lists/*
RUN pip install --no-cache-dir Pillow
WORKDIR /work/tools/reels
