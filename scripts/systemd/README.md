# Dev service units

Dragonfly (Redis-compatible), Meilisearch and Reverb as **systemd --user** units, so they
survive the terminal that started them and restart on failure. No root required.

    cp scripts/systemd/bp-*.service ~/.config/systemd/user/
    systemctl --user daemon-reload
    systemctl --user enable --now bp-dragonfly bp-meilisearch bp-reverb

`scripts/dev-services.sh {start,stop,status}` uses these units when installed and falls back to
detached processes otherwise.

To keep them running when you are not logged in (across reboots):

    sudo loginctl enable-linger "$USER"
