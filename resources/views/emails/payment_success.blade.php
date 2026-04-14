<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Pembayaran No. {{$noPermohonan}} Telah Berhasil</title>
</head>

<body>
    <h2>Halo, {{$userName}}</h2>
    <p>Kami ingin menyampaikan bahwa pembayaran untuk Nomor Surat Permohonan {{$noPermohonan}} dengan Nomor Transaksi {{$noTransaction}} telah berhasil diproses dengan rincian sebagai berikut:</p>
    </br>
    <table>
        <tr>
            <td>Nomor Permohonan: </td>
            <td>{{$noPermohonan}}</td>
        </tr>
        <tr>
            <td>Tanggal Transaksi:</td>
            <td>{{$date}}</td>
        </tr>
        <tr>
            <td>Nominal Transaksi:</td>
            <td>Rp. {{$total}}</td>
        </tr>
    </table>
    </br>
    <p>Saat ini pembayaran Anda sedang dalam proses validasi oleh Admin. Silakan melakukan pengecekan secara berkala pada Nomor Surat Permohonan Anda untuk mengetahui pembaruan status selanjutnya.</p>
    <p>Terima kasih atas perhatian dan kerja sama Anda.</p>
</body>

</html>