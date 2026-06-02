FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends prusa-slicer ca-certificates && rm -rf /var/lib/apt/lists/*
WORKDIR /work
ENTRYPOINT ["prusa-slicer"]
