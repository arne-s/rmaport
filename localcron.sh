#!/bin/bash

while true; do
  php artisan schedule:run
  
   # Calculate next run time, which is current time + 10 minute
  NEXT_RUN=$(date -v +10M +"%H:%M:%S")

  echo "Next run @ $NEXT_RUN"
  sleep 600
done
