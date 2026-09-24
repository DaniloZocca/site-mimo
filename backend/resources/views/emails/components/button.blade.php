@props([
    'url' => '#',
    'color' => '#7C3AED',
    'textColor' => '#FFFFFF',
    'align' => 'center'
])

<table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 20px 0;">
  <tr>
    <td align="{{ $align }}">
      <!--[if mso]>
      <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:48px;v-text-anchor:middle;width:260px;" arcsize="25%" strokeweight="2px" strokecolor="#18181B" fillcolor="{{ $color }}">
        <w:anchorlock/>
        <center style="color:{{ $textColor }};font-family:'Poppins',Helvetica,Arial,sans-serif;font-size:14px;font-weight:bold;">
          {{ $slot }}
        </center>
      </v:roundrect>
      <![endif]-->
      <!--[if !mso]><!-->
      <a href="{{ $url }}" target="_blank" style="display: inline-block; background-color: {{ $color }}; color: {{ $textColor }}; border: 2px solid #18181B; border-radius: 16px; padding: 14px 28px; font-size: 13px; font-weight: 700; text-decoration: none; text-transform: uppercase; letter-spacing: 0.5px; box-shadow: 3px 3px 0px #18181B; -webkit-text-size-adjust: none;">
        {{ $slot }}
      </a>
      <!--<![endif]-->
    </td>
  </tr>
</table>
