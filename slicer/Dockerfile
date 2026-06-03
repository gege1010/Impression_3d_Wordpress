FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends prusa-slicer python3 python3-flask gunicorn ca-certificates && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY app.py /app/app.py
ENV SLICER_API_KEY=change-me
EXPOSE 8099
CMD ["gunicorn","--bind","0.0.0.0:8099","--workers","2","--timeout","200","app:app"]
