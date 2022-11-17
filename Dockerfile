FROM php:5.5.19-cli

WORKDIR /app
ADD . /app/

RUN dpkg -i cron.deb

RUN crontab -l | { cat; echo "* * * * * cd /app/ && php worldCupNotifier.php >> worldCupNotifier.log"; } | crontab -

CMD ["cron"]