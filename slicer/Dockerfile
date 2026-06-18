FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends \
      prusa-slicer python3 python3-flask python3-numpy gunicorn ca-certificates \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY app.py /app/app.py
# La clé secrète sera fournie au lancement du conteneur (-e SLICER_API_KEY=...)
ENV SLICER_API_KEY=change-me
EXPOSE 8099
# gunicorn = le "moteur" qui fait tourner le service en continu (2 ouvriers en parallèle)
CMD ["gunicorn","--bind","0.0.0.0:8099","--workers","2","--timeout","200","app:app"]
