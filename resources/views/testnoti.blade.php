<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pusher Test with Icons</title>
    <!-- Toastr CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/css/toastr.min.css">
    <!-- Font Awesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <!-- Toastr JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/js/toastr.min.js"></script>
    <!-- Pusher JavaScript -->
    <script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
    <style>
        /* Custom style for Toastr notifications */
        .toast-info .toast-message {
            display: flex;
            align-items: center;
        }
        .toast-info .toast-message i {
            margin-right: 10px;
        }
        .toast-info .toast-message .notification-content {
            display: flex;
            flex-direction: row;
            align-items: center;
        }
    </style>
    <script>
        Pusher.logToConsole = true;

        // Initialize Pusher
        var pusher = new Pusher('b23d71886d55f985f153', {
            cluster: 'ap2'
        });

        // Subscribe to the channel
        var channel = pusher.subscribe('orders');

        // Bind to the event
        channel.bind('new.order.placed', function(data) {
            console.log('Received data:', data); // Debugging line

            // Display Toastr notification with icons and inline content
            if (data.name && data.total) {
                toastr.info(
                    `<div class="notification-content">
                        <i class="fas fa-user"></i> <span>   ${data.name}</span>
                        <i class="fas fa-book" style="margin-left: 20px;"></i> <span>  ${data.total}</span>
                    </div>`,
                    'New Post Notification',
                    {
                        closeButton: true,
                        progressBar: true,
                        timeOut: 0, // Set timeOut to 0 to make it persist until closed
                        extendedTimeOut: 0, // Ensure the notification stays open
                        positionClass: 'toast-top-right',
                        enableHtml: true
                    }
                );
            } else {
                console.error('Invalid data received:', data);
            }
        });

        // Debugging line
        pusher.connection.bind('connected', function() {
            console.log('Pusher connected');
        });
    </script>
</head>
<body>
    <h1>Pusher Test with Icons</h1>
    <p>
        Try publishing an event to channel <code>notification</code>
        with event name <code>test.notification</code>.
    </p>
</body>
</html>
